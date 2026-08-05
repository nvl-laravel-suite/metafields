<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(MetafieldsTables::METAFIELDS, function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Relations
            $table->uuid('definition_id')
                ->comment('Reference to the metafield definition');

            // Polymorphic owner supporting integer, UUID, ULID, and string keys.
            $table->string('metafieldable_type');
            $table->string('metafieldable_id');

            // For reference types only
            $table->string('referenced_id')->nullable()
                ->comment('Stores the ID of the referenced record (for REFERENCE type)');

            // Base value (for non-translatable fields)
            $table->text('value')->nullable()
                ->comment('Stores the actual value if NOT translatable');

            $table->unsignedBigInteger('revision')->default(1)
                ->comment('Optimistic concurrency revision');

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('referenced_id');
            $table->unique(
                ['metafieldable_type', 'metafieldable_id', 'definition_id'],
                'metafields_owner_definition_unique',
            );

            // Constraints
            $table->foreign('definition_id')
                ->references('id')
                ->on(MetafieldsTables::METAFIELDS_DEFINITIONS)
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::METAFIELDS);
    }
};
