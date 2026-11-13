<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Nvl\Support\Config\PackageStorage;

/** Preserve the value storage migration record. SQLite and PostgreSQL text already holds these values. */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return PackageStorage::connection('metafields');
    }

    public function up(): void {}

    public function down(): void {}
};
