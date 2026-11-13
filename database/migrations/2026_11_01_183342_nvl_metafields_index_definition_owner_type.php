<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

/** Supports definition-first reads and FK checks without changing owner identity. */
return new class extends Migration
{
    /** Use the actual package source and configured table mapping. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    /** Add a non-unique definition-leading index only when no existing index covers it. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $table = MetafieldsTables::get(MetafieldsTables::Metafields);
        foreach ($schema->getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, 2) === ['definition_id', 'metafieldable_type']) {
                return;
            }
        }
        $name = 'metafields_definition_owner_'.substr(hash('sha256', $schema->getConnection()->getTablePrefix().$table), 0, 16).'_idx';
        $schema->table($table, static fn (Blueprint $table) => $table->index(['definition_id', 'metafieldable_type'], $name));
    }

    /** Retain the non-unique lookup index during rollback. */
    public function down(): void {}
};
