<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionInput;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Enums\SystemActor;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * Stages an existing standalone metafield graph with its original identities.
 *
 * @api
 *
 * @phpstan-type Source array{connection?:string, owner_types?:array<string,string>, owner_morphs?:array<string,string>, owner_ids?:array<string,array<string,string>>, reference_types?:array<string,string>, reference_ids?:array<string,array<string,string>>}
 * @phpstan-type Review array{schema_fingerprint:string,fingerprint:string,source:array<string,int>,staged:array<string,int>,conflicts:list<string>}
 */
final readonly class LegacyMetafieldImporter
{
    private const array Common = ['id', 'created_at', 'updated_at', 'deleted_at'];

    private const array Graph = [
        'definitions' => ['metafields_definitions', MetafieldsTables::Definitions,
            ['namespace', 'key', 'handle', 'type', 'referenced_model_type', 'is_translatable', 'is_required', 'is_filterable', 'validation_rules', 'json_property_schema', 'title', 'description', 'hint', 'default_value', 'default_referenced_id', 'display_order'],
            ['namespace', 'key', 'handle', 'active_handle', 'type', 'referenced_model_type', 'is_translatable', 'is_required', 'is_filterable', 'validation_rules', 'json_property_schema', 'default_value', 'default_referenced_id', 'display_order', 'revision', 'archived_at'],
            ['active_handle'], null],
        'assignments' => ['metafield_definition_assignments', MetafieldsTables::DefinitionAssignments,
            ['definition_id', 'owner_type', 'section', 'display_order', 'is_required', 'is_active', 'ui_config', 'owner_scopes'],
            ['definition_id', 'owner_type', 'section', 'display_order', 'is_required', 'is_active', 'ui_config', 'owner_scopes'],
            ['definition_id', 'owner_type'], 'definition_id'],
        'values' => ['metafields', MetafieldsTables::Metafields,
            ['definition_id', 'metafieldable_type', 'metafieldable_id', 'referenced_id', 'value'],
            ['definition_id', 'metafieldable_type', 'metafieldable_id', 'referenced_id', 'value', 'revision'],
            ['metafieldable_type', 'metafieldable_id', 'definition_id'], 'definition_id'],
        'value_translations' => ['metafields_translations', MetafieldsTables::I18n, ['metafield_id', 'locale', 'value'], ['metafield_id', 'locale', 'value'],
            ['metafield_id', 'locale'], 'metafield_id'],
    ];

    public function __construct(
        private DatabaseManager $database,
        private MetafieldActorPolicy $actors,
        private LocaleCatalog $locales,
        private MetafieldOwnerRegistry $owners,
        private MetafieldDefinitionInput $definitionInput,
        private MetafieldReferenceRecordResolver $references,
    ) {}

    /**
     * Review the existing graph and translations without writing to either database.
     *
     * @param  Source  $source
     * @return Review
     */
    public function plan(Authenticatable|SystemActor $actor, string $sourceLocale, array $source = []): array
    {
        $this->admit($actor, $sourceLocale);

        return $this->inspect($sourceLocale, $source);
    }

    /**
     * Stage reviewed rows in resumable committed batches.
     *
     * @param  Source  $source
     * @return Review
     */
    public function apply(Authenticatable|SystemActor $actor, string $sourceLocale, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $sourceLocale, committed: true);
        $review = $this->inspect($sourceLocale, $source);
        $this->assertReviewed($review, $fingerprint);
        if ($review['source'] !== $review['staged']) {
            $this->assertReviewed($this->inspect($sourceLocale, $source, write: true), $fingerprint);
            $review = $this->inspect($sourceLocale, $source);
        }

        return $this->reconciled($review, $fingerprint);
    }

    /**
     * Reconcile the committed graph before the host activates native reads and writes.
     *
     * @param  Source  $source
     * @return Review
     */
    public function verify(Authenticatable|SystemActor $actor, string $sourceLocale, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $sourceLocale, committed: true);

        return $this->reconciled($this->inspect($sourceLocale, $source), $fingerprint);
    }

    private function admit(Authenticatable|SystemActor $actor, string $locale, bool $committed = false): void
    {
        $this->actors->authorize($actor, 'import');
        if (MetafieldOwnership::declared() || ($committed && MetafieldModelRegistry::newInstance()->getConnection()->transactionLevel() !== 0)) {
            throw new LogicException('Metafield adoption requires standalone storage and a committed target before switching to native Ownership or production use.');
        }
        if (! in_array(MetafieldModelRegistry::newInstance()->getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Legacy Metafield history requires partial indexes on PostgreSQL or SQLite.');
        }
        if (! $this->locales->supports($locale)) {
            throw new InvalidArgumentException('The legacy definition locale must be supported by the destination.');
        }
    }

    /**
     * @param  Source  $source
     * @return Review
     */
    private function inspect(string $locale, array $source, bool $write = false): array
    {
        $target = MetafieldModelRegistry::newInstance()->getConnection();
        $origin = isset($source['connection']) ? $this->database->connection($source['connection']) : $target;
        $schemas = [];
        foreach (self::Graph as [$legacy, $native, $legacyCols, $targetCols, $identity]) {
            $table = MetafieldsTables::get($native);
            if ($target === $origin && $legacy === $table) {
                throw new LogicException('Metafield adoption requires separate legacy and native tables.');
            }
            $schemas[] = $this->schema($origin, $legacy, [...self::Common, ...$legacyCols], strict: true);
            $schemas[] = $this->schema($target, $table, [...self::Common, ...$targetCols], $identity);
        }
        $copyTable = MetafieldsTables::get(MetafieldsTables::DefinitionsI18n);
        $schemas[] = $this->schema($target, $copyTable, ['id', 'metafield_definition_id', 'locale', 'title', 'description', 'hint', 'default_value', 'properties', 'created_at', 'updated_at'], ['metafield_definition_id', 'locale']);
        $schemaFingerprint = hash('sha256', json_encode($schemas, JSON_THROW_ON_ERROR));
        $digest = hash_init('sha256');
        hash_update($digest, $schemaFingerprint.$locale);
        $sourceCounts = $staged = ['definitions' => 0, 'definition_translations' => 0, 'assignments' => 0, 'values' => 0, 'value_translations' => 0];
        $conflicts = [];
        foreach (self::Graph as $kind => [$legacy, $native, , , $identity, $parentKey]) {
            $table = MetafieldsTables::get($native);
            $seen = [];
            hash_update($digest, $legacy."\n");
            foreach ($origin->table($legacy)->useWritePdo()->lazyById(50)->chunk(50) as $page) {
                $rows = $page->all();
                $existing = $target->table($table)->useWritePdo()->whereIn('id', array_column($rows, 'id'))->get()->keyBy('id');
                $copies = $kind === 'definitions' ? $target->table($copyTable)->useWritePdo()->whereIn('metafield_definition_id', array_column($rows, 'id'))->where('locale', $locale)->get()->keyBy('metafield_definition_id') : collect();
                $parents = $parentKey === null ? collect() : $origin->table($kind === 'value_translations' ? 'metafields' : 'metafields_definitions')
                    ->useWritePdo()->whereIn('id', array_column($rows, $parentKey))->get()->keyBy('id');
                $parentDefinitions = $kind === 'value_translations' ? $origin->table('metafields_definitions')->useWritePdo()
                    ->whereIn('id', $parents->pluck('definition_id'))->get()->keyBy('id') : collect();
                $pending = $pendingCopy = [];
                foreach ($rows as $row) {
                    $sourceCounts[$kind]++;
                    hash_update($digest, json_encode($row, JSON_THROW_ON_ERROR)."\n");
                    $idText = is_string($row->id) ? $row->id : '(invalid ID)';
                    try {
                        $id = $this->uuid($row->id);
                        $parent = $parentKey === null ? null : $parents->get($this->uuid($row->{$parentKey}));
                        if ($parentKey !== null && ! $parent instanceof stdClass) {
                            throw new LogicException('has no legacy parent.');
                        }
                        if ($kind === 'value_translations' && $parent instanceof stdClass) {
                            $parent = $parentDefinitions->get($this->uuid($parent->definition_id));
                        }
                        [$attributes, $copy] = $this->map($kind, $row, $parent instanceof stdClass ? $parent : null, $locale, $source);
                    } catch (InvalidArgumentException|LogicException|JsonException|ValidationException $exception) {
                        $conflicts[] = "{$legacy} [{$idText}] ".$exception->getMessage();

                        continue;
                    }
                    $mapped = ['id' => $id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at, 'deleted_at' => $row->deleted_at] + $attributes;
                    hash_update($digest, json_encode([$mapped, $copy], JSON_THROW_ON_ERROR)."\n");
                    if ($kind === 'assignments' || $row->deleted_at === null) {
                        $key = json_encode(array_intersect_key($mapped, array_flip($identity)), JSON_THROW_ON_ERROR);
                        if (isset($seen[$key])) {
                            $conflicts[] = "{$legacy} [{$id}] collides with source row [{$seen[$key]}].";
                        }
                        $seen[$key] = $id;
                    }
                    $current = $existing->get($id);
                    if ($current === null) {
                        $pending[] = $mapped;
                    } elseif ($this->matches($mapped, $current)) {
                        $staged[$kind]++;
                    } else {
                        $conflicts[] = "{$table} [{$id}] differs from the reviewed row.";
                    }
                    if ($copy !== null) {
                        $sourceCounts['definition_translations']++;
                        $currentCopy = $copies->get($id);
                        if ($currentCopy === null) {
                            $pendingCopy[] = $copy;
                        } elseif ($this->matches($copy, $currentCopy)) {
                            $staged['definition_translations']++;
                        } else {
                            $conflicts[] = "Definition [{$id}] has different staged localized copy.";
                        }
                    }
                }
                if ($write && $conflicts !== []) {
                    throw new LogicException($conflicts[0]);
                }
                if ($write && ($pending !== [] || $pendingCopy !== [])) {
                    $target->transaction(static function () use ($target, $table, $copyTable, $pending, $pendingCopy): void {
                        foreach ([$table => $pending, $copyTable => $pendingCopy] as $name => $batch) {
                            foreach (array_chunk($batch, 30) as $part) {
                                $target->table($name)->insert($part);
                            }
                        }
                    });
                    $staged[$kind] += count($pending);
                    $staged['definition_translations'] += count($pendingCopy);
                }
            }
            if ($target->table($table)->useWritePdo()->count() !== $staged[$kind]) {
                $conflicts[] = "{$table} contains rows outside the matching staged graph.";
            }
        }
        if ($target->table($copyTable)->useWritePdo()->count() !== $staged['definition_translations']) {
            $conflicts[] = "{$copyTable} contains rows outside the matching staged graph.";
        }

        return ['schema_fingerprint' => $schemaFingerprint, 'fingerprint' => hash_final($digest), 'source' => $sourceCounts, 'staged' => $staged, 'conflicts' => $conflicts];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $identity
     * @return array<string,mixed>
     */
    private function schema(Connection $connection, string $table, array $columns, array $identity = [], bool $strict = false): array
    {
        $schema = $connection->getSchemaBuilder();
        $fields = $schema->getColumns($table);
        $names = array_column($fields, 'name');
        if (array_diff($columns, $names) !== [] || ($strict && array_diff($names, $columns) !== [])
            || ! $schema->hasIndex($table, ['id'], 'primary') || ($identity !== [] && ! $schema->hasIndex($table, $identity, 'unique'))) {
            throw new LogicException("Metafield adoption table [{$table}] has an incompatible schema.");
        }

        return ['connection' => $connection->getName(), 'database' => $connection->getDatabaseName(), 'table' => $connection->getTablePrefix().$table,
            'driver' => $connection->getDriverName(), 'columns' => $fields, 'indexes' => $schema->getIndexes($table), 'foreign_keys' => $schema->getForeignKeys($table)];
    }

    /**
     * @param  Source  $source
     * @return array{array<string,mixed>,?array<string,mixed>}
     */
    private function map(string $kind, stdClass $row, ?stdClass $parent, string $locale, array $source): array
    {
        if ($kind === 'definitions') {
            return $this->definition($row, $locale, $source);
        }
        if ($parent === null) {
            throw new LogicException('has no legacy parent.');
        }

        return [match ($kind) {
            'assignments' => $this->assignment($row, $parent, $source),
            'values' => $this->value($row, $parent, $source),
            'value_translations' => $this->translation($row, $parent),
            default => throw new LogicException('has an unknown Metafield resource.'),
        }, null];
    }

    /**
     * @param  Source  $source
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function definition(stdClass $row, string $locale, array $source): array
    {
        $id = $this->uuid($row->id);
        $type = $this->type($row);
        $localized = $this->flag($row->is_translatable);
        $referenceType = $this->referenceType($row, $source);
        $legacyDefault = $this->mappedValue($row->default_value, $row->default_referenced_id, $row, $source);
        $default = $type->cast($legacyDefault);
        $namespace = $this->string($row->namespace);
        $key = $this->string($row->key);
        $handle = MetafieldDefinition::generateHandle($namespace, $key);
        if ($handle !== $row->handle || strlen($handle) > 255) {
            throw new LogicException('does not have a canonical bounded handle.');
        }
        $copy = [
            'title' => $this->string($row->title), 'description' => $row->description, 'hint' => $row->hint,
            ...($localized ? ['defaultValue' => $default] : []),
        ];
        $validated = $this->definitionInput->validate([
            'namespace' => $namespace, 'key' => $key, 'type' => $type, 'referencedModelType' => $referenceType,
            'isTranslatable' => $localized, 'isRequired' => $this->flag($row->is_required), 'isFilterable' => $this->flag($row->is_filterable),
            'validationRules' => $this->json($row->validation_rules), 'jsonPropertySchema' => $this->json($row->json_property_schema),
            'defaultValue' => $localized ? null : $default, 'displayOrder' => $this->number($row->display_order), 'translations' => [$locale => $copy],
        ], false);
        $reference = $type === MetafieldTypeEnum::Reference;

        return [[
            'namespace' => $namespace, 'key' => $key, 'handle' => $handle, 'active_handle' => $row->deleted_at === null ? $handle : null,
            'type' => $type->value, 'referenced_model_type' => $referenceType, 'is_translatable' => $localized,
            'is_required' => $validated['isRequired'] ?? false, 'is_filterable' => $validated['isFilterable'] ?? false,
            'validation_rules' => $row->validation_rules, 'json_property_schema' => $row->json_property_schema,
            'default_value' => $localized || $reference ? null : $legacyDefault,
            'default_referenced_id' => $reference ? $legacyDefault : null,
            'display_order' => $validated['displayOrder'] ?? 0, 'revision' => 1, 'archived_at' => null,
        ], [
            'id' => Uuid::uuid5($id, 'metafield-definition:'.$locale)->toString(), 'metafield_definition_id' => $id,
            'locale' => $locale, 'title' => $copy['title'], 'description' => $copy['description'], 'hint' => $copy['hint'],
            'default_value' => $localized ? $legacyDefault : null, 'properties' => null,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ]];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function assignment(stdClass $row, stdClass $definition, array $source): array
    {
        $ownerType = $this->string($row->owner_type);
        $assignment = [
            'ownerType' => $source['owner_types'][$ownerType] ?? $ownerType, 'section' => $this->string($row->section),
            'displayOrder' => $this->number($row->display_order), 'isRequired' => $this->flag($row->is_required),
            'isActive' => $this->flag($row->is_active), 'uiConfig' => $this->json($row->ui_config), 'ownerScopes' => $this->json($row->owner_scopes),
        ];
        $attributes = $this->definitionInput->validate([
            'namespace' => $this->string($definition->namespace), 'key' => $this->string($definition->key),
            'type' => $this->type($definition), 'referencedModelType' => $this->referenceType($definition, $source), 'assignment' => $assignment,
        ], false);

        return [
            'definition_id' => $this->uuid($row->definition_id), 'owner_type' => $assignment['ownerType'], 'section' => $assignment['section'],
            'display_order' => $assignment['displayOrder'], 'is_required' => $assignment['isRequired'], 'is_active' => $assignment['isActive'],
            'ui_config' => $row->ui_config, 'owner_scopes' => isset($attributes['assignment']['ownerScopes']) ? json_encode($attributes['assignment']['ownerScopes'], JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * @param  Source  $source
     * @return array<string,mixed>
     */
    private function value(stdClass $row, stdClass $definition, array $source): array
    {
        $type = $this->string($row->metafieldable_type);
        $oldId = $this->string($row->metafieldable_id);
        $ownerAlias = $source['owner_morphs'][$type] ?? $type;
        $model = $this->owners->configurationForType($ownerAlias)['model'];
        $instance = new $model;
        if ($instance->getConnection() !== MetafieldModelRegistry::newInstance()->getConnection()) {
            throw new LogicException('requires a canonical owner on the native Metafield connection.');
        }
        $query = $instance->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->withoutEagerLoads()->useWritePdo();
        MetafieldOwnerPredicate::equal($query, $instance->getQualifiedKeyName(), $source['owner_ids'][$type][$oldId] ?? $oldId);
        $owner = $query->first();
        if (! $owner instanceof Model) {
            throw new LogicException('has no matching native owner.');
        }
        $valueType = $this->type($definition);
        $value = $this->mappedValue($row->value, $row->referenced_id, $definition, $source);
        if ($this->flag($definition->is_translatable) && $value !== null) {
            throw new LogicException('carries a base value for a localized definition.');
        }

        return [
            'definition_id' => $this->uuid($row->definition_id), 'metafieldable_type' => $owner->getMorphClass(),
            'metafieldable_id' => ModelIdentifier::required($owner), 'revision' => 1,
            'referenced_id' => $valueType === MetafieldTypeEnum::Reference ? $value : null,
            'value' => $valueType === MetafieldTypeEnum::Reference ? null : $value,
        ];
    }

    /** @return array<string,mixed> */
    private function translation(stdClass $row, stdClass $definition): array
    {
        $locale = $this->string($row->locale);
        if (! $this->flag($definition->is_translatable) || ! $this->locales->supports($locale) || ! is_string($row->value)) {
            throw new LogicException('requires a supported legacy locale and stored translated value.');
        }
        $this->type($definition)->cast($row->value);

        return ['metafield_id' => $this->uuid($row->metafield_id), 'locale' => $locale, 'value' => $row->value];
    }

    /** @param Source $source */
    private function referenceType(stdClass $definition, array $source): ?string
    {
        if (! in_array($this->type($definition), [MetafieldTypeEnum::Reference, MetafieldTypeEnum::ReferenceList], true)) {
            return null;
        }
        $legacy = $this->string($definition->referenced_model_type);
        $alias = $source['reference_types'][$legacy] ?? $legacy;
        if (! MetafieldReferenceModelRegistry::isAllowedModelClass($alias)) {
            throw new LogicException('requires an explicit registered reference type.');
        }

        return $alias;
    }

    /** @param Source $source */
    private function mappedValue(mixed $value, mixed $id, stdClass $definition, array $source): ?string
    {
        $type = $this->type($definition);
        if ($id !== null && $type !== MetafieldTypeEnum::Reference) {
            throw new LogicException('has an unexpected reference identifier.');
        }
        if ($value !== null && ! is_string($value)) {
            throw new LogicException('has an incompatible stored value.');
        }
        if ($type === MetafieldTypeEnum::Reference) {
            if ($value !== null) {
                throw new LogicException('has both reference and scalar storage.');
            }

            return $id === null ? null : $this->reference($definition, $id, $source);
        }
        $decoded = $type->cast($value);
        if ($type === MetafieldTypeEnum::ReferenceList && $value !== null) {
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new LogicException('requires a stored reference list.');
            }

            return json_encode(array_map(fn (mixed $key): string => $this->reference($definition, $key, $source), $decoded), JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /** @param Source $source */
    private function reference(stdClass $definition, mixed $identifier, array $source): string
    {
        $type = $this->string($definition->referenced_model_type);
        $id = $this->string($identifier);
        $mapped = $source['reference_ids'][$type][$id] ?? $id;
        $record = $this->references->resolve($this->referenceType($definition, $source), $mapped);

        return $record instanceof Model ? ModelIdentifier::required($record) : throw new LogicException('has an unavailable mapped reference.');
    }

    private function type(stdClass $definition): MetafieldTypeEnum
    {
        return MetafieldTypeEnum::tryFrom($this->string($definition->type)) ?? throw new LogicException('has an unsupported legacy type.');
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 255 || str_contains($value, "\0")) {
            throw new LogicException('has an invalid required legacy string.');
        }

        return $value;
    }

    private function uuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value) || strtolower($value) !== $value) {
            throw new LogicException('requires a canonical UUID.');
        }

        return $value;
    }

    private function flag(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => throw new LogicException('has a malformed legacy boolean.'),
        };
    }

    private function number(mixed $value): int
    {
        $number = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]) : false;

        return $number === false ? throw new LogicException('has an invalid display order.') : $number;
    }

    /** @return array<array-key,mixed>|null */
    private function json(mixed $value): ?array
    {
        $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
        if ($decoded !== null && ! is_array($decoded)) {
            throw new LogicException('has invalid legacy JSON metadata.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $mapped */
    private function matches(array $mapped, stdClass $existing): bool
    {
        foreach ($mapped as $column => $value) {
            $actual = $existing->{$column} ?? null;
            if ($value === $actual || (is_bool($value) && in_array($actual, [$value, (int) $value, (string) (int) $value], true))) {
                continue;
            }
            if (in_array($column, ['json_property_schema', 'ui_config', 'owner_scopes'], true) && $this->json($actual) === $this->json($value)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param Review $review */
    private function assertReviewed(array $review, string $fingerprint): void
    {
        if (! hash_equals($fingerprint, $review['fingerprint'])) {
            throw new LogicException('Metafield adoption fingerprint changed. Review a new plan.');
        }
        if ($review['conflicts'] !== []) {
            throw new LogicException($review['conflicts'][0]);
        }
    }

    /**
     * @param  Review  $review
     * @return Review
     */
    private function reconciled(array $review, string $fingerprint): array
    {
        $this->assertReviewed($review, $fingerprint);
        if ($review['source'] !== $review['staged']) {
            throw new LogicException('Metafield adoption reconciliation is incomplete.');
        }

        return $review;
    }
}
