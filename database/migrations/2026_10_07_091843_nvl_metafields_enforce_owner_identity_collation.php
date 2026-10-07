<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Support\Config\PackageStorage;

return new class extends Migration
{
    /** Use the effective storage connection for both native schema directions. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    /** Store distinct scalar owner identities independently of the host's default collation. */
    public function up(): void
    {
        $this->collation('utf8mb4_bin');
    }

    /** Restore the configured host collation; conflicting identities cause a native failure. */
    public function down(): void
    {
        $connection = Schema::connection($this->getConnection())->getConnection();
        $this->collation((string) ($connection->getConfig('collation') ?? 'utf8mb4_unicode_ci'));
    }

    /** Change only the two declared polymorphic identity columns on MySQL-family storage. */
    private function collation(string $collation): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! in_array($schema->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $schema->table(MetafieldsTables::get(MetafieldsTables::Metafields), static function (Blueprint $table) use ($collation): void {
            $table->string('metafieldable_type')->collation($collation)->change();
            $table->string('metafieldable_id')->collation($collation)->change();
        });
    }
};
