<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Adopts exact reviewed Metafield records without guessing primary owners from associations. @internal */
final readonly class MetafieldAdoptionAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionStore $mappings, private OwnershipSchema $schema, private OwnershipDatabaseConstraints $constraints, private MetafieldAdoptionReferences $references) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['metafields.definitions', 'metafields.definition-assignments', 'metafields.definition-translations', 'metafields.values', 'metafields.value-translations', 'metafields.catalog-grants'];
    }

    /** Prepare additive partitions and replace only the package's known global receipt identity. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $root = new ($this->resources->get('metafields.definitions')->model);
        $connection = $root->getConnection();
        $schema = $connection->getSchemaBuilder();
        $grant = new MetafieldDefinitionOwnerGrant;
        if (! $schema->hasTable($grant->getTable())) {
            $schema->create($grant->getTable(), static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('definition_id');
                $table->unsignedBigInteger('source_revision');
                $table->unsignedBigInteger('revision')->default(1);
                $table->boolean('enabled')->default(true);
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
            });
        }
        foreach (['catalog_import_key', 'catalog_source_id', 'catalog_source_revision', 'catalog_source_hash', 'catalog_import_request_hash'] as $column) {
            if (! $schema->hasColumn($root->getTable(), $column)) {
                $schema->table($root->getTable(), static function (Blueprint $table) use ($column): void {
                    if ($column === 'catalog_source_revision') {
                        $table->unsignedBigInteger($column)->nullable();
                    } elseif (in_array($column, ['catalog_import_key', 'catalog_source_id'], true)) {
                        $table->uuid($column)->nullable();
                    } else {
                        $table->string($column, 64)->nullable();
                    }
                });
            }
        }
        foreach ($this->resources() as $key) {
            $model = new ($this->resources->get($key)->model);
            foreach (['tenant_id', 'ownership_key'] as $column) {
                if ($schema->hasColumn($model->getTable(), $column)) {
                    $schema->table($model->getTable(), static fn (Blueprint $table) => ($column === 'tenant_id' ? $table->uuid($column) : $table->string($column, 44))->nullable()->change());
                }
            }$this->schema->prepare($key);
        }
        foreach ([['metafields.definition-assignments', 'definition_id', 'metafields.definitions'], ['metafields.definition-translations', 'metafield_definition_id', 'metafields.definitions'], ['metafields.values', 'definition_id', 'metafields.definitions'], ['metafields.value-translations', 'metafield_id', 'metafields.values']] as [$childKey,$column,$parentKey]) {
            $child = new ($this->resources->get($childKey)->model);
            $parent = new ($this->resources->get($parentKey)->model);
            $found = false;
            foreach ($schema->getForeignKeys($child->getTable()) as $foreign) {
                if ($foreign['columns'] === [$column] && $foreign['foreign_table'] === $parent->getTable() && $foreign['foreign_columns'] === ['id']) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $name = 'metafields_native_'.substr(hash('sha256', $child->getTable().$column), 0, 24);
                $schema->table($child->getTable(), static fn (Blueprint $table) => $table->foreign([$column], $name)->references(['id'])->on($parent->getTable())->cascadeOnDelete());
            }
        }
        foreach ($schema->getIndexes($root->getTable()) as $index) {
            if ($index['unique'] && in_array($index['columns'], [['active_handle'], ['namespace', 'key'], ['ownership_key', 'active_handle']], true)) {
                $schema->table($root->getTable(), static fn (Blueprint $table) => $table->dropUnique($index['name']));
            }
        }
        $this->unique($root, ['owner_scope_key', 'active_handle'], 'metafields_owner_active_handle_unique');
        $this->unique($root, ['owner_scope_key', 'catalog_import_key'], 'metafields_owner_import_unique');
        $this->unique($grant, ['owner_scope_key', 'definition_id'], 'metafields_owner_grant_unique');
    }

    /** @param list<string> $columns */
    private function unique(Model $model, array $columns, string $name): void
    {
        $schema = $model->getConnection()->getSchemaBuilder();
        foreach ($schema->getIndexes($model->getTable()) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }$schema->table($model->getTable(), static fn (Blueprint $table) => $table->unique($columns, $name));
    }

    /** Backfill bounded actual records from explicit immutable per-record mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $processed = 0;
        do {
            $result = $this->backfillResource($plan, $cursor, $limit - $processed);
            $processed += $result->processed;
            $cursor = $result->nextCursor;
        } while ($cursor !== null && $processed < $limit);

        return new OwnershipBackfillResult($cursor, $processed);
    }

    /** Consume at most one resource page without counting empty tables as progress. */
    private function backfillResource(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $parts = $cursor === null ? ['0', ''] : explode(':', $cursor, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0])) {
            throw new OwnershipConfigurationInvalid('Invalid Metafield adoption cursor.');
        }
        $keys = $this->resources();
        $position = (int) $parts[0];
        $key = $keys[$position] ?? throw new OwnershipConfigurationInvalid('Invalid Metafield adoption resource.');
        $definition = $this->resources->get($key);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $rows = $connection->table($model->getTable())->when($parts[1] !== '', static fn ($query) => $query->where('id', '>', $parts[1]))->orderBy('id')->limit($limit)->get();
        $last = '';
        $connection->transaction(function () use ($rows, $key, $plan, $definition, $model, $connection, &$last): void {
            foreach ($rows as $row) {
                if (! is_string($row->id)) {
                    throw new OwnershipConfigurationInvalid('Metafield adoption requires canonical UUID records.');
                }
                $mapping = $this->mappings->forRecord($plan, $key, $row->id);
                $owner = $mapping->owner;
                if (isset($row->tenant_id) && $mapping->legacyTenantId !== $row->tenant_id) {
                    throw new OwnershipConfigurationInvalid('Reviewed Metafield mappings must preserve the original tenant identity.');
                }
                $nativeOwner = $definition->ownership->foreignKey === null ? null : ($row->{$definition->ownership->foreignKey} ?? null);
                if ($nativeOwner !== null && ((! is_int($nativeOwner) && ! is_string($nativeOwner)) || (string) $nativeOwner !== $owner?->key)) {
                    throw new OwnershipConfigurationInvalid('Metafield adoption cannot relabel an existing native primary owner.');
                }
                $attributes = ['owner_scope_key' => $owner?->scopeKey() ?? 'central'];
                $ownership = $definition->ownership;
                if ($ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = $owner === null ? null : json_encode($owner->toArray(), JSON_THROW_ON_ERROR);
                } elseif ($ownership->strategy === 'column') {
                    $attributes[$ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $owner?->key;
                } elseif ($ownership->strategy === 'pivot' && $owner !== null) {
                    $connection->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insertOrIgnore([
                        $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $row->id,
                        $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key,
                        'owner_scope_key' => $owner->scopeKey(),
                    ]);
                }
                $connection->table($model->getTable())->where('id', $row->id)->update($attributes);
                $last = $row->id;
            }
        });
        $next = $rows->count() === $limit ? $position.':'.$last : ($position + 1 < count($keys) ? ($position + 1).':' : null);

        return new OwnershipBackfillResult($next, $rows->count());
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $key) {
            $this->schema->verify($key);
        }

        $value = new ($this->resources->get('metafields.values')->model);
        $definition = new ($this->resources->get('metafields.definitions')->model);
        $bad = $value->getConnection()->table($value->getTable().' as v')->leftJoin($definition->getTable().' as d', 'd.id', '=', 'v.definition_id')->where(static fn (Builder $q) => $q->whereNull('d.id')->orWhereColumn('d.owner_scope_key', '!=', 'v.owner_scope_key'))->exists();
        if ($bad) {
            throw new OwnershipConfigurationInvalid('Metafield definitions and values must retain the same canonical owner.');
        }

        $this->references->verify();

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $key) {
            $this->schema->constrain($key);
        }
        $value = new ($this->resources->get('metafields.values')->model);
        $definition = new ($this->resources->get('metafields.definitions')->model);
        $this->constraints->reference($value->getConnection(), $value->getTable(), 'definition_id', $definition->getTable(), 'id');
    }
}
