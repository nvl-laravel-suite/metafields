<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(MetafieldsTables::DefinitionAssignments, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('definition_id');
            $table->string('owner_type');
            $table->string('section')->default('general');
            $table->integer('display_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('ui_config')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['owner_type', 'is_active', 'section'],
                'metafield_assignment_owner_active_section_idx',
            );
            $table->unique(
                ['definition_id', 'owner_type'],
                'metafield_definition_assignments_unique',
            );
            $table->foreign('definition_id')
                ->references('id')
                ->on(MetafieldsTables::Definitions)
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::DefinitionAssignments);
    }
};
