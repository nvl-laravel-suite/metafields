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
        Schema::create(MetafieldsTables::METAFIELDS_DEFINITIONS_I18N, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('metafield_definition_id');
            $table->string('locale', 35);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('hint')->nullable();
            $table->text('default_value')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->unique(
                ['metafield_definition_id', 'locale'],
                'metafields_definitions_i18n_owner_locale_unique',
            );
            $table->foreign('metafield_definition_id')
                ->references('id')
                ->on(MetafieldsTables::METAFIELDS_DEFINITIONS)
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::METAFIELDS_DEFINITIONS_I18N);
    }
};
