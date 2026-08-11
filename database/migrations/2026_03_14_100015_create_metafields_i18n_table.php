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
        Schema::create(MetafieldsTables::I18n, function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('metafield_id')
                ->comment('Reference to the parent metafield record');

            $table->string('locale', 35)
                ->comment('Normalized locale code, including regional variants');

            $table->text('value')
                ->comment('The translated value');

            $table->timestamps();

            // Unique constraint: one translation per locale per metafield
            $table->unique(['metafield_id', 'locale']);

            // Foreign key
            $table->foreign('metafield_id')
                ->references('id')
                ->on(MetafieldsTables::Metafields)
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(MetafieldsTables::I18n);
    }
};
