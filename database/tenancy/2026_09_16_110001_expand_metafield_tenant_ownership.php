<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

return new class extends Migration
{
    /** Add nullable adoption columns without changing legacy visibility. */
    public function up(): void
    {
        $partitioned = config('tenancy.sharing.metafields') === 'copy'
            || config('tenancy.resources.metafields') === 'platform';

        $this->expand(MetafieldsTables::Definitions, static function (Blueprint $table) use ($partitioned): void {
            $table->uuid('tenant_id')->nullable()->index('metafield_definitions_tenant_idx');
            if ($partitioned) {
                $table->string('ownership_key', 44)->nullable()->index('metafield_definitions_ownership_idx');
            }
            $table->uuid('catalog_import_key')->nullable();
            $table->uuid('catalog_source_id')->nullable();
            $table->unsignedBigInteger('catalog_source_revision')->nullable();
            $table->char('catalog_source_hash', 64)->nullable();
            $table->char('catalog_import_request_hash', 64)->nullable();
        });

        foreach ([MetafieldsTables::DefinitionAssignments, MetafieldsTables::DefinitionsI18n] as $child) {
            $this->expand($child, static function (Blueprint $table) use ($partitioned, $child): void {
                $table->uuid('tenant_id')->nullable()->index($child.'_tenant_idx');
                if ($partitioned) {
                    $table->string('ownership_key', 44)->nullable()->index($child.'_ownership_idx');
                }
            });
        }

        foreach ([MetafieldsTables::Metafields, MetafieldsTables::I18n] as $child) {
            $this->expand($child, static function (Blueprint $table) use ($child): void {
                $table->uuid('tenant_id')->nullable()->index($child.'_tenant_idx');
            });
        }

        if (! Schema::hasTable(MetafieldsTables::TenantAdoptionCopies)) {
            Schema::create(MetafieldsTables::TenantAdoptionCopies, static function (Blueprint $table): void {
                $table->uuid('adoption_run_id');
                $table->uuid('source_id');
                $table->uuid('tenant_id');
                $table->uuid('destination_id');
                $table->string('status', 32);
                $table->timestamps();
                $table->primary(['adoption_run_id', 'source_id', 'tenant_id'], 'metafield_adoption_copies_primary');
                $table->unique(['adoption_run_id', 'destination_id'], 'metafield_adoption_destination_unique');
            });
        }
    }

    /** Remove only nullable adoption expansion. */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::TenantAdoptionCopies);
        $this->contract(MetafieldsTables::Definitions, [
            'tenant_id', 'ownership_key', 'catalog_import_key', 'catalog_source_id',
            'catalog_source_revision', 'catalog_source_hash',
            'catalog_import_request_hash',
        ]);
        foreach ([MetafieldsTables::DefinitionAssignments, MetafieldsTables::DefinitionsI18n] as $child) {
            $this->contract($child, ['tenant_id', 'ownership_key']);
        }
        foreach ([MetafieldsTables::Metafields, MetafieldsTables::I18n] as $child) {
            $this->contract($child, ['tenant_id']);
        }
    }

    /** Add columns only when the configured table exists and remains untouched. */
    private function expand(string $table, Closure $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'tenant_id')) {
            Schema::table($table, $callback);
        }
    }

    /** @param list<string> $columns */
    private function contract(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $present = array_values(array_filter($columns, static fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($present !== []) {
            Schema::table($table, static fn (Blueprint $blueprint) => $blueprint->dropColumn($present));
        }
    }
};
