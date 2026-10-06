<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

    /** Create concrete grant identity locks and recipient grants. */
    public function up(): void
    {
        if (! Schema::connection(PackageStorage::connection('metafields'))->hasTable(MetafieldsTables::get(MetafieldsTables::TenantGrantLocks))) {
            Schema::connection(PackageStorage::connection('metafields'))->create(MetafieldsTables::get(MetafieldsTables::TenantGrantLocks), static function (Blueprint $table): void {
                $table->uuid('tenant_id');
                $table->uuid('definition_id');
                $table->timestamps();
                $table->primary(['tenant_id', 'definition_id'], 'metafield_definition_grant_locks_primary');
            });
        }
        if (Schema::connection(PackageStorage::connection('metafields'))->hasTable(MetafieldsTables::get(MetafieldsTables::TenantGrants))) {
            throw new LogicException('Existing package table is not owned by this migration. Run nvl:doctor --strict and use nvl:schema:upgrade for a verified legacy installation.');
        }
        Schema::connection(PackageStorage::connection('metafields'))->create(MetafieldsTables::get(MetafieldsTables::TenantGrants), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('definition_id');
            $table->unsignedBigInteger('source_revision');
            $table->unsignedBigInteger('revision')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'definition_id'], 'metafield_definition_grants_identity_unique');
            $table->unique(['tenant_id', 'id'], 'metafield_definition_grants_tenant_id_unique');
            $table->index(['tenant_id', 'enabled', 'created_at'], 'metafield_definition_grants_lookup_idx');
            $table->foreign('definition_id')->references('id')->on(MetafieldsTables::get(MetafieldsTables::Definitions))->cascadeOnDelete();
        });
    }

    /** Drop the concrete grant schema. */
    public function down(): void
    {
        Schema::connection(PackageStorage::connection('metafields'))->dropIfExists(MetafieldsTables::get(MetafieldsTables::TenantGrants));
        Schema::connection(PackageStorage::connection('metafields'))->dropIfExists(MetafieldsTables::get(MetafieldsTables::TenantGrantLocks));
    }
};
