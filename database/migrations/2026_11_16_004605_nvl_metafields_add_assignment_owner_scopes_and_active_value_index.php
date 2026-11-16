<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    private const string Index = 'metafields_owner_definition_unique';

    private const array Identity = ['metafieldable_type', 'metafieldable_id', 'definition_id'];

    private const string TranslationIndex = 'nvl_metafields_active_translation_identity_unique';

    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $assignments = MetafieldsTables::get(MetafieldsTables::DefinitionAssignments);
        $schema->table($assignments, static fn (Blueprint $table) => $table->json('owner_scopes')->nullable());
        $translations = MetafieldsTables::get(MetafieldsTables::I18n);
        $schema->table($translations, static fn (Blueprint $table) => $table->softDeletes());
        $connection = $schema->getConnection();
        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }
        $values = MetafieldsTables::get(MetafieldsTables::Metafields);
        $schema->table($values, static fn (Blueprint $table) => $table->dropUnique(self::Index));
        $schema->table($translations, static fn (Blueprint $table) => $table->dropUnique(['metafield_id', 'locale']));
        $this->uniqueActive($connection, $values, self::Index, self::Identity);
        $this->uniqueActive($connection, $translations, self::TranslationIndex, ['metafield_id', 'locale']);
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        $values = MetafieldsTables::get(MetafieldsTables::Metafields);
        $translations = MetafieldsTables::get(MetafieldsTables::I18n);
        if ($schema->hasColumn($translations, 'deleted_at') && $connection->table($translations)->whereNotNull('deleted_at')->exists()) {
            throw new LogicException('Archive cleared Metafield translations before rolling back this migration.');
        }
        if (in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true) && $schema->hasTable($values) && $schema->hasIndex($values, self::Index)) {
            if ($connection->table($values)->select(self::Identity)->groupBy(self::Identity)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new LogicException('Resolve Metafield value history with duplicate owner identities before rolling back this migration.');
            }
            $schema->table($values, static function (Blueprint $table): void {
                $table->dropIndex(self::Index);
                $table->unique(self::Identity, self::Index);
            });
        }
        if ($schema->hasColumn($translations, 'deleted_at')) {
            if ($schema->hasIndex($translations, self::TranslationIndex)) {
                $schema->table($translations, static function (Blueprint $table): void {
                    $table->dropIndex(self::TranslationIndex);
                    $table->unique(['metafield_id', 'locale']);
                });
            }
            $schema->table($translations, static fn (Blueprint $table) => $table->dropSoftDeletes());
        }
        $assignments = MetafieldsTables::get(MetafieldsTables::DefinitionAssignments);
        if ($schema->hasColumn($assignments, 'owner_scopes')) {
            $schema->table($assignments, static fn (Blueprint $table) => $table->dropColumn('owner_scopes'));
        }
    }

    /** @param list<string> $identity */
    private function uniqueActive(Connection $connection, string $table, string $name, array $identity): void
    {
        $grammar = $connection->getQueryGrammar();
        $table = $grammar->wrapTable($table);
        $columns = implode(', ', $grammar->wrapArray($identity));
        $index = $grammar->wrap($name);
        $connection->statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$columns}) WHERE deleted_at IS NULL");
    }
};
