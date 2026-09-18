<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Data\MetafieldDefinitionCatalogSnapshot;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Persists independent tenant definition schemas from authorized snapshots. */
final readonly class MetafieldDefinitionImporter
{
    /** Create the transaction-bound importer. */
    public function __construct(
        private MetafieldDefinitionCatalogReader $reader,
        private MetafieldDefinitionWriter $writer,
        private MetafieldDefinitionAssignmentSyncer $assignments,
        private MetafieldReferenceRecordResolver $references,
        private TenantContext $context,
        private TenantDirectory $directory,
        private EffectiveTenantConnection $connections,
    ) {}

    /** Persist one authorized definition copy in the caller's transaction. */
    public function persist(MetafieldDefinitionCatalogSnapshot $source, ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition
    {
        $connection = $this->connection();
        $tenant = $this->context->requireTenant();
        if ($connection->transactionLevel() < 1 || ! Str::isUuid($data->idempotencyKey)
            || $source->grantId !== $data->grantId
            || $source->grantRevision !== $data->expectedGrantRevision
            || $source->sourceRevision !== $data->expectedSourceRevision) {
            throw new TenantBoundaryViolation('The Metafield import tuple is invalid or no transaction is active.');
        }
        if ($this->directory->find(new TenantId($tenant->value))->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('The Metafield catalog recipient is no longer active.');
        }

        $connection->table(MetafieldsTables::TenantGrantLocks)->insertOrIgnore([
            'tenant_id' => $tenant->value,
            'definition_id' => $source->sourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connection->table(MetafieldsTables::TenantGrantLocks)
            ->where('tenant_id', $tenant->value)
            ->where('definition_id', $source->sourceId)
            ->lockForUpdate()->first();
        $grant = MetafieldDefinitionTenantGrant::withoutGlobalScope('tenant')
            ->whereKey($source->grantId)->where('tenant_id', $tenant->value)->lockForUpdate()->first();
        $platform = MetafieldDefinition::withoutGlobalScope('tenant')
            ->whereKey($source->sourceId)->whereNull('tenant_id')->where('ownership_key', 'platform')->lockForUpdate()->first();
        $connection->table(MetafieldsTables::DefinitionAssignments)
            ->where('definition_id', $source->sourceId)->orderBy('id')->lockForUpdate()->get();
        $connection->table(MetafieldsTables::DefinitionsI18n)
            ->where('metafield_definition_id', $source->sourceId)->orderBy('id')->lockForUpdate()->get();
        if (! $grant instanceof MetafieldDefinitionTenantGrant || ! $grant->enabled || $grant->revoked_at !== null
            || $grant->revision !== $source->grantRevision || $grant->source_revision !== $source->sourceRevision
            || ! $platform instanceof MetafieldDefinition || $platform->revision !== $source->sourceRevision) {
            throw new TenantBoundaryViolation('The Metafield grant or source changed before import.');
        }
        $locked = $this->reader->find($source->grantId);
        if ($locked->sourceHash !== $source->sourceHash) {
            throw new TenantBoundaryViolation('The platform Metafield schema changed before import.');
        }

        [$sourceReferenceIds, $referenceHash] = $this->normalizedReferenceMap($source, $data);
        $requestHash = hash('sha256', json_encode([
            $source->sourceId,
            $source->sourceRevision,
            $source->sourceHash,
            $data->namespace,
            $data->key,
            $data->assignment->toArray(),
            $referenceHash,
        ], JSON_THROW_ON_ERROR));
        $existing = MetafieldDefinition::query()->where('catalog_import_key', $data->idempotencyKey)->lockForUpdate()->first();
        if ($existing instanceof MetafieldDefinition) {
            if ($existing->catalog_source_id !== $source->sourceId
                || $existing->catalog_source_revision !== $source->sourceRevision
                || $existing->catalog_source_hash !== $source->sourceHash
                || $existing->catalog_import_request_hash !== $requestHash) {
                throw new TenantBoundaryViolation('The Metafield import key belongs to another request.');
            }

            return $existing->load(['assignments', 'translations']);
        }
        $mappedDefault = $this->mappedDefault($source, $sourceReferenceIds, $referenceHash);
        $handle = MetafieldDefinition::generateHandle($data->namespace, $data->key);
        if (MetafieldDefinition::query()->active()->where('active_handle', $handle)->exists()) {
            throw new TenantBoundaryViolation('The requested Metafield definition handle is already in use.');
        }

        $definition = $source->definition;
        $payload = CreateMetafieldDefinitionPayload::from([
            'namespace' => $data->namespace,
            'key' => $data->key,
            'type' => $definition->type,
            'assignment' => $data->assignment,
            'referencedModelType' => $definition->referencedModelType,
            'isTranslatable' => $definition->isTranslatable,
            'isRequired' => $definition->isRequired,
            'isFilterable' => $definition->isFilterable,
            'validationRules' => $definition->validationRules,
            'jsonPropertySchema' => $definition->jsonPropertySchema?->toArray(),
            'defaultValue' => $mappedDefault,
            'displayOrder' => $definition->displayOrder,
            'translations' => $definition->translations,
        ]);
        $copy = $this->writer->createImported($payload, [
            'catalog_import_key' => $data->idempotencyKey,
            'catalog_source_id' => $source->sourceId,
            'catalog_source_revision' => $source->sourceRevision,
            'catalog_source_hash' => $source->sourceHash,
            'catalog_import_request_hash' => $requestHash,
        ]);
        $this->assignments->sync($copy, $data->assignment);

        return $copy->refresh()->load(['assignments', 'translations']);
    }

    /** @return array{0: list<string>, 1: array<string, string>} */
    private function normalizedReferenceMap(MetafieldDefinitionCatalogSnapshot $source, ImportPlatformMetafieldDefinitionData $data): array
    {
        $definition = $source->definition;
        $sourceIds = match ($definition->type) {
            MetafieldTypeEnum::Reference => is_string($definition->defaultValue) ? [$definition->defaultValue] : [],
            MetafieldTypeEnum::ReferenceList => is_array($definition->defaultValue) ? array_values($definition->defaultValue) : [],
            default => [],
        };
        $sourceIds = array_values(array_unique(array_filter($sourceIds, is_string(...))));
        $map = $data->referenceMap;
        ksort($map);
        $expected = $sourceIds;
        sort($expected);
        if (array_keys($map) !== $expected || ($sourceIds === [] && $map !== [])) {
            throw new TenantBoundaryViolation('Metafield reference defaults require one exact total reference map.');
        }

        return [$sourceIds, $map];
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, string>  $map
     */
    private function mappedDefault(MetafieldDefinitionCatalogSnapshot $source, array $sourceIds, array $map): mixed
    {
        $definition = $source->definition;
        foreach ($map as $sourceId => $targetId) {
            $target = $this->references->resolve($definition->referencedModelType, $targetId, lock: true);
            if (! $target instanceof Model) {
                throw new TenantBoundaryViolation('A mapped Metafield reference target is unavailable.');
            }
            $targetId = $target->getAttribute($target->getKeyName());
            if (! is_string($targetId) || $targetId === '') {
                throw new TenantBoundaryViolation('A mapped Metafield reference target has no canonical identifier.');
            }
            $map[$sourceId] = $targetId;
        }
        $mapped = match ($definition->type) {
            MetafieldTypeEnum::Reference => $sourceIds === [] ? null : $map[$sourceIds[0]],
            MetafieldTypeEnum::ReferenceList => array_map(static fn (string $id): string => $map[$id], $sourceIds),
            default => $definition->defaultValue,
        };

        return $mapped;
    }

    /** Return the exact canonical package connection. */
    private function connection(): Connection
    {
        $connection = (new Metafield)->getConnection();
        if ($connection !== $this->connections->core()) {
            throw new TenantBoundaryViolation('Metafield catalog import requires the canonical tenant connection.');
        }

        return $connection;
    }
}
