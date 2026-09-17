<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\Contracts\TenantAdoptionMetadataValidator;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\EffectiveTenantConnection;
use Nvl\Tenancy\Services\TenantAdoptionMappings;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantAssignment;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use stdClass;

/** Owns reviewed Metafield graph expansion, splitting, verification, and activation. */
final readonly class MetafieldAdoptionAdapter implements TenantAdoptionAdapter, TenantAdoptionMetadataValidator
{
    private const array RESOURCES = [
        'metafields.definitions',
        'metafields.definition-assignments',
        'metafields.definition-translations',
        'metafields.values',
        'metafields.value-translations',
        'metafields.catalog-grants',
    ];

    /** Create the package-owned adoption boundary. */
    public function __construct(
        private Migrator $migrator,
        private TenantAdoptionMappings $mappings,
        private EffectiveTenantConnection $connections,
        private Repository $configuration,
        private MetafieldOwnerRegistry $owners,
    ) {}

    /** Validate exact reviewed definition split metadata. */
    public function validateAssignment(TenantAssignment $assignment): void
    {
        if ($assignment->resource !== 'metafields.definitions') {
            if ($assignment->metadata !== []) {
                throw new TenantConfigurationInvalid('Only Metafield definitions accept adoption metadata.');
            }

            return;
        }
        $unknown = array_diff(array_keys($assignment->metadata), ['splits', 'source_disposition']);
        $splits = $assignment->metadata['splits'] ?? [];
        if ($unknown !== [] || ! is_array($splits) || count($splits) > 100
            || ($splits !== [] && ($assignment->metadata['source_disposition'] ?? null) !== 'retain-primary')) {
            throw new TenantConfigurationInvalid('Metafield definition split metadata is invalid.');
        }
        $tenants = [];
        $destinations = [];
        foreach ($splits as $split) {
            if (! is_array($split) || count($split) !== 2
                || array_diff(array_keys($split), ['tenant_id', 'destination_id']) !== []
                || ! is_string($split['tenant_id']) || ! Str::isUuid($split['tenant_id'])
                || ! is_string($split['destination_id']) || ! Str::isUuid($split['destination_id'])
                || isset($tenants[$split['tenant_id']]) || isset($destinations[$split['destination_id']])) {
                throw new TenantConfigurationInvalid('Metafield split identities must be unique canonical UUIDs.');
            }
            $tenants[$split['tenant_id']] = true;
            $destinations[$split['destination_id']] = true;
        }
    }

    /** @return list<string> */
    public function resources(): array
    {
        return self::RESOURCES;
    }

    /** Apply only nullable expansion and concrete grant schema. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $this->assertConnection($plan);
        $path = dirname(__DIR__, 2).'/database/tenancy';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([
            $path.'/2026_09_16_110001_expand_metafield_tenant_ownership.php',
            $path.'/2026_09_16_110002_create_metafield_definition_tenant_grants.php',
        ], ['force' => true]));
    }

    /** Backfill one bounded definition batch and its entire value graph. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        $this->assertConnection($plan);
        $batch = $this->mappings->assignments($plan, 'metafields.definitions', $cursor, $limit);
        foreach ($batch as $assignment) {
            $this->backfillDefinition($plan, $assignment);
        }
        if ($batch === []) {
            return new TenantBackfillResult(null, 0);
        }
        $last = $batch[array_key_last($batch)];

        return new TenantBackfillResult($last->recordId, count($batch));
    }

    /** Verify mapping completeness, child inheritance, owner identity, and copy ledgers. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $this->assertConnection($plan);
        $connection = $this->connection($plan);
        $schema = $connection->getSchemaBuilder();
        $errors = [];
        foreach ([
            MetafieldsTables::Definitions,
            MetafieldsTables::DefinitionAssignments,
            MetafieldsTables::DefinitionsI18n,
            MetafieldsTables::Metafields,
            MetafieldsTables::I18n,
            MetafieldsTables::TenantGrants,
        ] as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'tenant_id')) {
                $errors[] = $table.'.tenant_id';
            }
        }
        $partitioned = $this->partitioned();
        $definitions = $connection->table(MetafieldsTables::Definitions)->get(['id', 'tenant_id', ...($partitioned ? ['ownership_key'] : [])]);
        foreach ($definitions as $definition) {
            if (! is_string($definition->id)
                || ($this->platformOwned()
                    ? ($definition->tenant_id !== null || ($definition->ownership_key ?? null) !== 'platform')
                    : (! is_string($definition->tenant_id)
                        || ($partitioned && ($definition->ownership_key ?? null) !== 'tenant:'.$definition->tenant_id)))) {
                $errors[] = 'metafields.definitions.unmapped:'.(is_string($definition->id) ? $definition->id : 'unknown');
            }
        }
        foreach ([
            MetafieldsTables::DefinitionAssignments => 'definition_id',
            MetafieldsTables::DefinitionsI18n => 'metafield_definition_id',
        ] as $child => $foreign) {
            $rows = $connection->table($child.' as child')
                ->leftJoin(MetafieldsTables::Definitions.' as root', 'root.id', '=', 'child.'.$foreign)
                ->where(function ($query) use ($partitioned): void {
                    $query->whereNull('root.id')->orWhereColumn('child.tenant_id', '!=', 'root.tenant_id');
                    if ($partitioned) {
                        $query->orWhereColumn('child.ownership_key', '!=', 'root.ownership_key');
                    }
                })->limit(25)->pluck('child.id');
            foreach ($rows as $id) {
                $errors[] = $child.'.ownership:'.$id;
            }
        }
        foreach ($connection->table(MetafieldsTables::Metafields)->get(['id', 'definition_id', 'metafieldable_type', 'metafieldable_id', 'tenant_id']) as $value) {
            if (! is_string($value->tenant_id) || $this->ownerTenant($value) !== $value->tenant_id
                || ! $connection->table(MetafieldsTables::Definitions)->where('id', $value->definition_id)->where('tenant_id', $value->tenant_id)->exists()) {
                $errors[] = 'metafields.values.ownership:'.$value->id;
            }
        }
        if ($schema->hasTable(MetafieldsTables::TenantAdoptionCopies)
            && $connection->table(MetafieldsTables::TenantAdoptionCopies)->where('adoption_run_id', $plan->id)->where('status', '!=', 'committed')->exists()) {
            $errors[] = 'metafields.definitions.copy_incomplete';
        }

        return new TenantVerification(array_slice(array_values(array_unique($errors)), 0, 100));
    }

    /** Apply final constraints only after complete verification. */
    public function activate(TenantAdoptionPlan $plan): void
    {
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Metafield tenant schema did not verify before activation.');
        }
        $path = dirname(__DIR__, 2).'/database/tenancy/2026_09_16_110003_constrain_metafield_tenant_ownership.php';
        $this->migrator->usingConnection($plan->connection, fn () => $this->migrator->run([$path], ['force' => true]));
        if (! $this->verify($plan)->passed()) {
            throw new TenantBoundaryViolation('Metafield tenant schema did not verify after activation.');
        }
    }

    /** Backfill one definition, copying it for every reviewed canonical owner tenant. */
    private function backfillDefinition(TenantAdoptionPlan $plan, TenantAssignment $assignment): void
    {
        $connection = $this->connection($plan);
        $source = $connection->table(MetafieldsTables::Definitions)->where('id', $assignment->recordId)->first();
        if (! $source instanceof stdClass) {
            throw new TenantBoundaryViolation('A reviewed Metafield definition is unavailable.');
        }
        if ($this->platformOwned()) {
            $connection->transaction(fn () => $this->writeDefinitionOwnership($connection, $source->id, null, 'platform'));

            return;
        }

        $destinations = [$assignment->tenantId->value => $assignment->recordId];
        foreach (($assignment->metadata['splits'] ?? []) as $split) {
            $destinations[$split['tenant_id']] = $split['destination_id'];
        }
        ksort($destinations);
        $valueTenants = [];
        foreach ($connection->table(MetafieldsTables::Metafields)->where('definition_id', $source->id)->get() as $value) {
            $valueTenants[$this->ownerTenant($value)] = true;
        }
        if (array_diff(array_keys($valueTenants), array_keys($destinations)) !== []) {
            throw new TenantBoundaryViolation('Metafield split metadata does not cover every canonical owner tenant.');
        }

        $connection->transaction(function () use ($connection, $plan, $source, $destinations): void {
            $assignments = $connection->table(MetafieldsTables::DefinitionAssignments)->where('definition_id', $source->id)->get();
            $translations = $connection->table(MetafieldsTables::DefinitionsI18n)->where('metafield_definition_id', $source->id)->get();
            foreach ($destinations as $tenant => $destination) {
                $ownershipKey = $this->partitioned() ? 'tenant:'.$tenant : null;
                if ($destination !== $source->id && ! $connection->table(MetafieldsTables::Definitions)->where('id', $destination)->exists()) {
                    $attributes = (array) $source;
                    $attributes['id'] = $destination;
                    $attributes['tenant_id'] = $tenant;
                    if ($this->partitioned()) {
                        $attributes['ownership_key'] = $ownershipKey;
                    }
                    $connection->table(MetafieldsTables::Definitions)->insert($attributes);
                    foreach ($assignments as $row) {
                        $copy = (array) $row;
                        $copy['id'] = (string) Str::uuid();
                        $copy['definition_id'] = $destination;
                        $copy['tenant_id'] = $tenant;
                        if ($this->partitioned()) {
                            $copy['ownership_key'] = $ownershipKey;
                        }
                        $connection->table(MetafieldsTables::DefinitionAssignments)->insert($copy);
                    }
                    foreach ($translations as $row) {
                        $copy = (array) $row;
                        $copy['id'] = (string) Str::uuid();
                        $copy['metafield_definition_id'] = $destination;
                        $copy['tenant_id'] = $tenant;
                        if ($this->partitioned()) {
                            $copy['ownership_key'] = $ownershipKey;
                        }
                        $connection->table(MetafieldsTables::DefinitionsI18n)->insert($copy);
                    }
                }
                $this->writeDefinitionOwnership($connection, $destination, $tenant, $ownershipKey);
                foreach ($connection->table(MetafieldsTables::Metafields)->where('definition_id', $source->id)->get() as $value) {
                    if ($this->ownerTenant($value) !== $tenant) {
                        continue;
                    }
                    $connection->table(MetafieldsTables::Metafields)->where('id', $value->id)->update([
                        'tenant_id' => $tenant,
                        'definition_id' => $destination,
                    ]);
                    $connection->table(MetafieldsTables::I18n)->where('metafield_id', $value->id)->update(['tenant_id' => $tenant]);
                }
                $connection->table(MetafieldsTables::TenantAdoptionCopies)->updateOrInsert([
                    'adoption_run_id' => $plan->id,
                    'source_id' => $source->id,
                    'tenant_id' => $tenant,
                ], [
                    'destination_id' => $destination,
                    'status' => 'committed',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** Apply one definition partition to the root and all definition-owned children. */
    private function writeDefinitionOwnership(Connection $connection, string $definitionId, ?string $tenant, ?string $ownershipKey): void
    {
        $ownership = ['tenant_id' => $tenant];
        if ($this->partitioned()) {
            $ownership['ownership_key'] = $ownershipKey;
        }
        $connection->table(MetafieldsTables::Definitions)->where('id', $definitionId)->update($ownership);
        $connection->table(MetafieldsTables::DefinitionAssignments)->where('definition_id', $definitionId)->update($ownership);
        $connection->table(MetafieldsTables::DefinitionsI18n)->where('metafield_definition_id', $definitionId)->update($ownership);
    }

    /** Resolve canonical owner tenant from the registered owner model, including soft-deleted rows. */
    private function ownerTenant(stdClass $value): string
    {
        $type = $value->metafieldable_type ?? null;
        $id = $value->metafieldable_id ?? null;
        if (! is_string($type) || ! is_string($id)) {
            throw new TenantBoundaryViolation('A Metafield value has invalid canonical owner identity.');
        }
        $configuration = $this->owners->configurationForType($type);
        $class = $configuration['model'];
        $model = new $class;
        $owner = $model->newQueryWithoutScopes()->whereKey($id)->first();
        $tenant = $owner?->getAttribute('tenant_id');
        if (! $owner instanceof Model || ! is_string($tenant) || ! Str::isUuid($tenant)) {
            throw new TenantBoundaryViolation('A Metafield value owner has no reviewed tenant identity.');
        }

        return strtolower($tenant);
    }

    /** Require the plan's normalized connection. */
    private function assertConnection(TenantAdoptionPlan $plan): void
    {
        if ($plan->connection !== $this->connections->core()->getName() || $this->connection($plan) !== $this->connections->core()) {
            throw new TenantConfigurationInvalid('Metafields adoption requires the canonical tenant connection.');
        }
    }

    /** Return the exact plan connection. */
    private function connection(TenantAdoptionPlan $plan): Connection
    {
        return (new MetafieldDefinition)->setConnection($plan->connection)->getConnection();
    }

    /** Determine whether definition roots carry the mixed partition discriminator. */
    private function partitioned(): bool
    {
        return $this->configuration->get('tenancy.sharing.metafields') === 'copy' || $this->platformOwned();
    }

    /** Determine whether this family is structurally platform-owned. */
    private function platformOwned(): bool
    {
        return $this->configuration->get('tenancy.resources.metafields') === 'platform';
    }
}
