<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package connection for Laravel's migration transaction. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    /** Apply verified tenant partitions and concrete parent constraints. */
    public function up(): void
    {
        $mixed = config('nvl-tenancy.sharing.metafields') === 'copy';
        $platform = config('nvl-tenancy.resources.metafields') === 'platform';
        $partitioned = $mixed || $platform;
        $schema = Schema::connection(PackageStorage::connection('metafields'));
        $partition = $partitioned ? 'ownership_key' : 'tenant_id';

        $this->dropIndex($schema, MetafieldsTables::get(MetafieldsTables::Definitions), 'metafields_definitions_active_handle_unique', true);
        $this->required($schema, MetafieldsTables::get(MetafieldsTables::Definitions), 'tenant_id', $partitioned);
        if ($partitioned) {
            $this->required($schema, MetafieldsTables::get(MetafieldsTables::Definitions), 'ownership_key', false, 'string');
            $this->ownershipCheck(DB::connection(), MetafieldsTables::get(MetafieldsTables::Definitions));
        }
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Definitions), [$partition, 'id'], 'metafield_definitions_partition_id_unique');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Definitions), ['tenant_id', 'id'], 'metafield_definitions_tenant_id_unique');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Definitions), [$partition, 'active_handle'], 'metafield_definitions_partition_handle_unique');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Definitions), ['tenant_id', 'catalog_import_key'], 'metafield_definitions_tenant_import_unique');

        foreach ([MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), MetafieldsTables::get(MetafieldsTables::DefinitionsI18n)] as $child) {
            $this->required($schema, $child, 'tenant_id', $partitioned);
            if ($partitioned) {
                $this->required($schema, $child, 'ownership_key', false, 'string');
                $this->ownershipCheck(DB::connection(), $child);
            }
            $this->dropForeignTo($schema, $child, MetafieldsTables::get(MetafieldsTables::Definitions));
            $this->foreign($schema, $child, [$partition, $this->definitionColumn($child)], MetafieldsTables::get(MetafieldsTables::Definitions), [$partition, 'id'], $child.'_definition_partition_foreign');
        }
        $this->dropIndex($schema, MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), 'metafield_definition_assignments_unique', true);
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), [$partition, 'definition_id', 'owner_type'], 'metafield_assignments_partition_owner_unique');
        $this->dropUniqueContaining($schema, MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), ['metafield_definition_id', 'locale']);
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), [$partition, 'metafield_definition_id', 'locale'], 'metafield_definition_i18n_partition_locale_unique');

        $this->required($schema, MetafieldsTables::get(MetafieldsTables::Metafields), 'tenant_id', false);
        $this->dropForeignTo($schema, MetafieldsTables::get(MetafieldsTables::Metafields), MetafieldsTables::get(MetafieldsTables::Definitions));
        $this->dropIndex($schema, MetafieldsTables::get(MetafieldsTables::Metafields), 'metafields_owner_definition_unique', true);
        $this->foreign($schema, MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'definition_id'], MetafieldsTables::get(MetafieldsTables::Definitions), ['tenant_id', 'id'], 'metafields_tenant_definition_foreign');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'id'], 'metafields_tenant_id_unique');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'metafieldable_type', 'metafieldable_id', 'definition_id'], 'metafields_tenant_owner_definition_unique');
        $this->index($schema, MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'referenced_id'], 'metafields_tenant_reference_idx');

        $this->required($schema, MetafieldsTables::get(MetafieldsTables::I18n), 'tenant_id', false);
        $this->dropForeignTo($schema, MetafieldsTables::get(MetafieldsTables::I18n), MetafieldsTables::get(MetafieldsTables::Metafields));
        $this->dropUniqueContaining($schema, MetafieldsTables::get(MetafieldsTables::I18n), ['metafield_id', 'locale']);
        $this->foreign($schema, MetafieldsTables::get(MetafieldsTables::I18n), ['tenant_id', 'metafield_id'], MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'id'], 'metafield_i18n_tenant_parent_foreign');
        $this->unique($schema, MetafieldsTables::get(MetafieldsTables::I18n), ['tenant_id', 'metafield_id', 'locale'], 'metafield_i18n_tenant_locale_unique');
        $schema->enableForeignKeyConstraints();
    }

    /** Remove final constraints while retaining expanded adoption data. */
    public function down(): void
    {
        $schema = Schema::connection(PackageStorage::connection('metafields'));
        $partition = config('nvl-tenancy.sharing.metafields') === 'copy'
            || config('nvl-tenancy.resources.metafields') === 'platform'
            ? 'ownership_key'
            : 'tenant_id';
        foreach ([
            [MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), [$partition, 'definition_id']],
            [MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), [$partition, 'metafield_definition_id']],
            [MetafieldsTables::get(MetafieldsTables::Metafields), ['tenant_id', 'definition_id']],
            [MetafieldsTables::get(MetafieldsTables::I18n), ['tenant_id', 'metafield_id']],
        ] as [$table, $columns]) {
            $this->dropForeign($schema, $table, $columns);
        }
        foreach ([
            [MetafieldsTables::get(MetafieldsTables::Definitions), 'metafield_definitions_partition_id_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Definitions), 'metafield_definitions_tenant_id_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Definitions), 'metafield_definitions_partition_handle_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Definitions), 'metafield_definitions_tenant_import_unique', true],
            [MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), 'metafield_assignments_partition_owner_unique', true],
            [MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), 'metafield_definition_i18n_partition_locale_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Metafields), 'metafields_tenant_id_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Metafields), 'metafields_tenant_owner_definition_unique', true],
            [MetafieldsTables::get(MetafieldsTables::Metafields), 'metafields_tenant_reference_idx', false],
            [MetafieldsTables::get(MetafieldsTables::I18n), 'metafield_i18n_tenant_locale_unique', true],
        ] as [$table, $name, $unique]) {
            $this->dropIndex($schema, $table, $name, $unique);
        }
        foreach ([MetafieldsTables::get(MetafieldsTables::Definitions), MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), MetafieldsTables::get(MetafieldsTables::DefinitionsI18n)] as $table) {
            $this->dropOwnershipCheck(DB::connection(), $table);
        }
        foreach ([MetafieldsTables::get(MetafieldsTables::Definitions), MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), MetafieldsTables::get(MetafieldsTables::Metafields), MetafieldsTables::get(MetafieldsTables::I18n)] as $table) {
            $this->required($schema, $table, 'tenant_id', true);
        }
        foreach ([MetafieldsTables::get(MetafieldsTables::Definitions), MetafieldsTables::get(MetafieldsTables::DefinitionAssignments), MetafieldsTables::get(MetafieldsTables::DefinitionsI18n)] as $table) {
            $this->required($schema, $table, 'ownership_key', true, 'string');
        }
    }

    /** Return the concrete definition foreign-key column. */
    private function definitionColumn(string $table): string
    {
        return $table === MetafieldsTables::get(MetafieldsTables::DefinitionsI18n) ? 'metafield_definition_id' : 'definition_id';
    }

    /** Change one prepared ownership column to final nullability. */
    private function required(Builder $schema, string $table, string $column, bool $nullable, string $type = 'uuid'): void
    {
        if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
            $schema->table($table, static function (Blueprint $blueprint) use ($column, $nullable, $type): void {
                $definition = $type === 'string' ? $blueprint->string($column, 44) : $blueprint->uuid($column);
                $definition->nullable($nullable)->change();
            });
        }
    }

    /** Drop every foreign key targeting one parent table. */
    private function dropForeignTo(Builder $schema, string $table, string $parent): void
    {
        foreach ($schema->getForeignKeys($table) as $foreign) {
            if (($foreign['foreign_table'] ?? null) === $parent) {
                $name = $foreign['name'] ?? null;
                $columns = $foreign['columns'] ?? [];
                $this->dropForeign($schema, $table, is_string($name) ? $name : $columns);
            }
        }
    }

    /** Drop one named foreign key when present. */
    private function dropForeign(Builder $schema, string $table, string|array $identifier): void
    {
        if ($schema->hasTable($table) && array_any(
            $schema->getForeignKeys($table),
            static fn (array $foreign): bool => is_string($identifier)
                ? ($foreign['name'] ?? null) === $identifier
                : ($foreign['columns'] ?? []) === $identifier,
        )) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->dropForeign($identifier));
        }
    }

    /** Drop a legacy unique with the exact ordered columns. */
    private function dropUniqueContaining(Builder $schema, string $table, array $columns): void
    {
        foreach ($schema->getIndexes($table) as $index) {
            if (($index['unique'] ?? false) === true && ($index['columns'] ?? []) === $columns) {
                $this->dropIndex($schema, $table, $index['name'], true);
            }
        }
    }

    /** Drop one named index when present. */
    private function dropIndex(Builder $schema, string $table, string $name, bool $unique): void
    {
        if ($schema->hasTable($table) && $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name));
        }
    }

    /** Add one unique index idempotently. */
    private function unique(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        }
    }

    /** Add one query index idempotently. */
    private function index(Builder $schema, string $table, array $columns, string $name): void
    {
        if (! $schema->hasIndex($table, $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    /** Add one concrete composite parent constraint idempotently. */
    private function foreign(Builder $schema, string $table, array $columns, string $parent, array $parentColumns, string $name): void
    {
        if (! array_any($schema->getForeignKeys($table), static fn (array $foreign): bool => $foreign['name'] === $name)) {
            $schema->table($table, static fn (Blueprint $blueprint) => $blueprint->foreign($columns, $name)->references($parentColumns)->on($parent)->cascadeOnDelete());
        }
    }

    /** Enforce the canonical mixed ownership discriminator. */
    private function ownershipCheck(Connection $connection, string $table): void
    {
        $name = $table.'_ownership_check';
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            foreach (['insert', 'update'] as $operation) {
                $trigger = $name.'_'.$operation;
                $connection->unprepared("CREATE TRIGGER IF NOT EXISTS {$trigger} BEFORE ".strtoupper($operation)." ON {$table} BEGIN SELECT CASE WHEN NOT ((NEW.ownership_key = 'platform' AND NEW.tenant_id IS NULL) OR (NEW.tenant_id IS NOT NULL AND NEW.ownership_key = 'tenant:' || NEW.tenant_id)) THEN RAISE(ABORT, 'invalid metafield ownership') END; END");
            }

            return;
        }
        $expression = in_array($driver, ['mysql', 'mariadb'], true)
            ? "((ownership_key = 'platform' AND tenant_id IS NULL) OR (tenant_id IS NOT NULL AND ownership_key = CONCAT('tenant:', tenant_id)))"
            : "((ownership_key = 'platform' AND tenant_id IS NULL) OR (tenant_id IS NOT NULL AND ownership_key = 'tenant:' || tenant_id))";
        try {
            $connection->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        } catch (Throwable $exception) {
            if (! str_contains(mb_strtolower($exception->getMessage()), 'already exists') && ! str_contains(mb_strtolower($exception->getMessage()), 'duplicate')) {
                throw $exception;
            }
        }
    }

    /** Remove the portable mixed ownership guard. */
    private function dropOwnershipCheck(Connection $connection, string $table): void
    {
        $name = $table.'_ownership_check';
        if ($connection->getDriverName() === 'sqlite') {
            $connection->unprepared("DROP TRIGGER IF EXISTS {$name}_insert");
            $connection->unprepared("DROP TRIGGER IF EXISTS {$name}_update");

            return;
        }
        $sql = $connection instanceof MySqlConnection && ! $connection->isMaria()
            ? "ALTER TABLE {$table} DROP CHECK {$name}"
            : "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}";
        try {
            $connection->statement($sql);
        } catch (Throwable $exception) {
            if (! str_contains(mb_strtolower($exception->getMessage()), 'does not exist')
                && ! str_contains(mb_strtolower($exception->getMessage()), 'is not found')
                && ! str_contains(mb_strtolower($exception->getMessage()), 'check that column/key exists')) {
                throw $exception;
            }
        }
    }
};
