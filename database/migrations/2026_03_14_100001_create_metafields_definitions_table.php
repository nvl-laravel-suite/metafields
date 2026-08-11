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
        Schema::create(MetafieldsTables::Definitions, function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Identifier fields
            $table->string('namespace')
                ->comment('Groups related metafields under a stable consumer-defined alias');

            $table->string('key')
                ->comment('Specific identifier within the namespace');

            $table->string('handle')
                ->comment('Unique slug combining namespace and key');

            $table->string('active_handle')->nullable()
                ->comment('Portable uniqueness projection for non-archived definitions');

            // Type and configuration
            $table->string('type')
                ->comment('The MetafieldTypeEnum value defining the data type');

            $table->string('referenced_model_type')->nullable()
                ->comment('If type is REFERENCE, defines what model this metafield can reference');

            $table->boolean('is_translatable')->default(false)
                ->comment('Whether this metafield supports translations');

            $table->boolean('is_required')->default(false)
                ->comment('Whether this metafield is required when setting values');

            $table->boolean('is_filterable')->default(false)
                ->comment('Whether this metafield can be used for filtering');

            // Validation
            $table->text('validation_rules')->nullable()
                ->comment('Additional Laravel validation rules as a JSON array');

            $table->jsonb('json_property_schema')->nullable()
                ->comment('Structured property schema for JSON metafields');

            $table->text('default_value')->nullable()
                ->comment('Serialized default value for non-reference metafield types');

            $table->string('default_referenced_id')->nullable()
                ->comment('Default referenced record identifier for reference metafields');

            $table->integer('display_order')->default(0)
                ->comment('Order for display in forms and UI');

            $table->unsignedBigInteger('revision')->default(1)
                ->comment('Optimistic concurrency revision');

            $table->timestamp('archived_at')->nullable()
                ->comment('When the definition was archived');

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['namespace', 'key']);
            $table->unique(
                'active_handle',
                'metafields_definitions_active_handle_unique',
            );
            $table->index(
                ['archived_at', 'deleted_at'],
                'metafields_definitions_archived_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::Definitions);
    }
};
