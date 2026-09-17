<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

return new class extends Migration
{
    /** Create concrete grant identity locks and recipient grants. */
    public function up(): void
    {
        if (! Schema::hasTable(MetafieldsTables::TenantGrantLocks)) {
            Schema::create(MetafieldsTables::TenantGrantLocks, static function (Blueprint $table): void {
                $table->uuid('tenant_id');
                $table->uuid('definition_id');
                $table->timestamps();
                $table->primary(['tenant_id', 'definition_id'], 'metafield_definition_grant_locks_primary');
            });
        }
        if (Schema::hasTable(MetafieldsTables::TenantGrants)) {
            return;
        }
        Schema::create(MetafieldsTables::TenantGrants, static function (Blueprint $table): void {
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
            $table->foreign('definition_id')->references('id')->on(MetafieldsTables::Definitions)->cascadeOnDelete();
        });
    }

    /** Drop the concrete grant schema. */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::TenantGrants);
        Schema::dropIfExists(MetafieldsTables::TenantGrantLocks);
    }
};
