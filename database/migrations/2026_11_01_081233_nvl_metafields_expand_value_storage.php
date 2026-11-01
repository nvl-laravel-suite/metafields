<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective package storage for schema changes and rollback preflight. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    /** Honor bounded structured values and defaults beyond MySQL's TEXT capacity. */
    public function up(): void
    {
        $this->resize(true);
    }

    /** Refuse to narrow storage when any accepted value would be lost. */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->columns() as $column) {
            if ($schema->getConnection()->table($column['table'])->whereRaw('OCTET_LENGTH('.$schema->getConnection()->getQueryGrammar()->wrap($column['column']).') > ?', [65_535])->exists()) {
                throw new LogicException('Cannot narrow metafield storage while values exceed TEXT capacity.');
            }
        }
        $this->resize(false);
    }

    /** Widen MySQL-family TEXT only; PostgreSQL and SQLite text already admit these values. */
    private function resize(bool $expand): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach ($this->columns() as $column) {
            $schema->table($column['table'], static function (Blueprint $table) use ($column, $expand): void {
                $definition = $expand ? $table->longText($column['column']) : $table->text($column['column']);
                $definition->nullable($column['nullable'])->comment($column['comment'])->change();
            });
        }
    }

    /**
     * @return list<array{table: string, column: string, nullable: bool, comment: string|null}>
     */
    private function columns(): array
    {
        return [
            ['table' => MetafieldsTables::get(MetafieldsTables::Metafields), 'column' => 'value', 'nullable' => true, 'comment' => 'Stores the actual value if NOT translatable'],
            ['table' => MetafieldsTables::get(MetafieldsTables::I18n), 'column' => 'value', 'nullable' => false, 'comment' => 'The translated value'],
            ['table' => MetafieldsTables::get(MetafieldsTables::Definitions), 'column' => 'default_value', 'nullable' => true, 'comment' => 'Serialized default value for non-reference metafield types'],
            ['table' => MetafieldsTables::get(MetafieldsTables::DefinitionsI18n), 'column' => 'default_value', 'nullable' => true, 'comment' => null],
        ];
    }
};
