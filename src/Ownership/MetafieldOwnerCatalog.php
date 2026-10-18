<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Data\MetafieldDefinitionCatalogSnapshot;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogProjector;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipReadiness;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipScope;

/** Copies explicitly granted central revisions into independent native-owner schemas. @api */
final readonly class MetafieldOwnerCatalog
{
    public function __construct(private OwnershipBoundary $boundary, private OwnershipContext $context, private OwnershipReadiness $readiness, private OwnershipResourceRegistry $resources, private MetafieldCatalogAccess $access, private MetafieldDefinitionCatalogProjector $projection, private MetafieldDefinitionWriter $writer, private MetafieldDefinitionAssignmentSyncer $assignments, private MetafieldReferenceRecordResolver $references) {}

    /** Grant one exact source revision to the currently admitted recipient only. */
    public function grant(string $sourceId, int $revision): MetafieldDefinitionOwnerGrant
    {
        $this->admit();
        $this->access->assertCanGrant($sourceId, $revision);

        return $this->connection()->transaction(function () use ($sourceId, $revision): MetafieldDefinitionOwnerGrant {
            $source = $this->source($sourceId, true);
            if ($source->revision !== $revision) {
                throw new OwnershipViolation('The central definition revision changed.');
            }
            $grant = MetafieldDefinitionOwnerGrant::query()->where('definition_id', $sourceId)->lockForUpdate()->first() ?? new MetafieldDefinitionOwnerGrant;
            $grant->forceFill(['definition_id' => $sourceId, 'source_revision' => $revision, 'revision' => $grant->exists ? $grant->revision + 1 : 1, 'enabled' => true, 'revoked_at' => null])->save();

            return $grant;
        });
    }

    public function revoke(string $grantId, int $expectedRevision): MetafieldDefinitionOwnerGrant
    {
        $this->admit();
        $this->access->assertCanRevoke($grantId);

        return $this->connection()->transaction(function () use ($grantId, $expectedRevision): MetafieldDefinitionOwnerGrant {
            $grant = MetafieldDefinitionOwnerGrant::query()->whereKey($grantId)->lockForUpdate()->first() ?? throw new OwnershipViolation('The definition grant is unavailable.');
            if ($grant->revision !== $expectedRevision) {
                throw new OwnershipViolation('The definition grant revision changed.');
            }
            $grant->forceFill(['enabled' => false, 'revoked_at' => now(), 'revision' => $grant->revision + 1])->save();

            return $grant;
        });
    }

    /** Project only one current capability without exposing general central reads. */
    public function inspect(string $grantId): MetafieldDefinitionCatalogSnapshot
    {
        $this->admit();

        return $this->snapshot($grantId, false);
    }

    /** Join the actual source transaction and recheck the grant and source under locks. */
    public function persist(MetafieldDefinitionCatalogSnapshot $source, ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition
    {
        $this->admit();
        $connection = $this->connection();
        if ($connection->transactionLevel() < 1 || ! Str::isUuid($data->idempotencyKey) || $source->grantId !== $data->grantId || $source->grantRevision !== $data->expectedGrantRevision || $source->sourceRevision !== $data->expectedSourceRevision) {
            throw new OwnershipViolation('A definition copy requires its exact reviewed tuple and source transaction.');
        }
        if ($this->snapshot($source->grantId, true) != $source) {
            throw new OwnershipViolation('The definition snapshot is stale or forged.');
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
        $existing = MetafieldModelRegistry::query()->where('catalog_import_key', $data->idempotencyKey)->lockForUpdate()->first();
        if ($existing instanceof MetafieldDefinition) {
            if ($existing->catalog_source_id !== $source->sourceId
                || $existing->catalog_source_revision !== $source->sourceRevision
                || $existing->catalog_source_hash !== $source->sourceHash
                || $existing->catalog_import_request_hash !== $requestHash) {
                throw new OwnershipViolation('The Metafield import key belongs to another request.');
            }

            return $existing->load(['assignments', 'translations']);
        }
        $mappedDefault = $this->mappedDefault($source, $sourceReferenceIds, $referenceHash);
        $handle = MetafieldDefinition::generateHandle($data->namespace, $data->key);
        if (MetafieldModelRegistry::query()->active()->where('active_handle', $handle)->exists()) {
            throw new OwnershipViolation('The requested Metafield definition handle is already in use.');
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

    private function snapshot(string $grantId, bool $lock): MetafieldDefinitionCatalogSnapshot
    {
        $query = MetafieldDefinitionOwnerGrant::query()->whereKey($grantId);
        $initial = $query->first() ?? throw new OwnershipViolation('The definition grant is unavailable.');
        $source = $this->source($initial->definition_id, $lock);
        $grant = $lock ? $query->lockForUpdate()->first() : $initial;
        if ($grant === null || $grant->definition_id !== $source->id || ! $grant->enabled || $grant->revoked_at !== null || $grant->source_revision !== $source->revision) {
            throw new OwnershipViolation('The definition grant or source revision is unavailable.');
        }
        $translations = MetafieldDefinitionTranslation::query()->withoutGlobalScope(OwnershipScope::class)->useWritePdo()->where('metafield_definition_id', $source->id)->where('owner_scope_key', 'central')->orderBy('locale');
        if ($lock) {
            $translations->lockForUpdate();
        }

        return $this->projection->project($source, $grant, $translations->get());
    }

    private function source(string $id, bool $lock): MetafieldDefinition
    {
        $this->readiness->assertActive('metafields.definitions');
        $this->readiness->assertActive('metafields.definition-translations');
        $query = MetafieldModelRegistry::query()->withoutGlobalScope(OwnershipScope::class)->useWritePdo()->whereKey($id)->where('owner_scope_key', 'central')->whereNull('archived_at');
        $ownership = $this->resources->get('metafields.definitions')->ownership;
        if ($ownership->strategy === 'column') {
            $query->whereNull($ownership->foreignKey ?? throw new OwnershipViolation);
        } elseif ($ownership->strategy === 'pivot') {
            $root = $query->getModel();
            $query->whereNotExists(fn (Builder $pivot) => $pivot->selectRaw('1')->from($ownership->table ?? throw new OwnershipViolation)->whereColumn($ownership->resourceKey ?? throw new OwnershipViolation, $root->getQualifiedKeyName()));
        } else {
            throw new OwnershipViolation('Central definitions require a native primary owner strategy.');
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new OwnershipViolation('The central definition is unavailable.');
    }

    private function admit(): void
    {
        if ($this->context->snapshot()->mode !== OwnershipContextMode::Owner || ! $this->resources->get('metafields.definitions')->allowsCentral) {
            throw new OwnershipViolation('Central schema copies require an explicit catalog and admitted recipient.');
        }
        $this->boundary->attributes('metafields.catalog-grants');
        foreach ([[MetafieldModelRegistry::newInstance()->getTable(), ['owner_scope_key', 'catalog_import_key']], [(new MetafieldDefinitionOwnerGrant)->getTable(), ['owner_scope_key', 'definition_id']]] as [$table,$columns]) {
            $found = false;
            foreach ($this->connection()->getSchemaBuilder()->getIndexes($table) as $index) {
                if ($index['unique'] && $index['columns'] === $columns) {
                    $found = true;
                    break;
                }
            }if (! $found) {
                throw new OwnershipViolation('A native definition catalog identity is missing.');
            }
        }
    }

    private function connection(): Connection
    {
        return MetafieldModelRegistry::newInstance()->getConnection();
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
            throw new OwnershipViolation('Metafield reference defaults require one exact total reference map.');
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
                throw new OwnershipViolation('A mapped Metafield reference target is unavailable.');
            }
            $targetId = $target->getAttribute($target->getKeyName());
            if (! is_string($targetId) || $targetId === '') {
                throw new OwnershipViolation('A mapped Metafield reference target has no canonical identifier.');
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
}
