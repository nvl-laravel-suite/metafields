<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

/**
 * Keep soft-deleted assignment history beside its active replacement: only active assignments are unique per owner type.
 */
return new class extends Migration
{
    private const string Index = 'metafield_definition_assignments_unique';

    private const string ActiveIndex = 'nvl_metafields_active_assignment_identity_unique';

    private const array Identity = ['definition_id', 'owner_type'];

    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }
        $assignments = MetafieldsTables::get(MetafieldsTables::DefinitionAssignments);
        $schema->table($assignments, static fn (Blueprint $table) => $table->dropUnique(self::Index));
        $grammar = $connection->getQueryGrammar();
        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s) WHERE deleted_at IS NULL',
            $grammar->wrap(self::ActiveIndex),
            $grammar->wrapTable($assignments),
            implode(', ', $grammar->wrapArray(self::Identity)),
        ));
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $connection = $schema->getConnection();
        $assignments = MetafieldsTables::get(MetafieldsTables::DefinitionAssignments);
        if (! in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true) || ! $schema->hasIndex($assignments, self::ActiveIndex)) {
            return;
        }
        if ($connection->table($assignments)->select(self::Identity)->groupBy(self::Identity)->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Resolve Metafield assignment history with duplicate owner types before rolling back this migration.');
        }
        $schema->table($assignments, static function (Blueprint $table): void {
            $table->dropIndex(self::ActiveIndex);
            $table->unique(self::Identity, self::Index);
        });
    }
};
