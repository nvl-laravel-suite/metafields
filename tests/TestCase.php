<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Tests\Fixtures\BatchSoftDeletingMetafieldOwner;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Metafields and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LocaleServiceProvider::class,
            DataServiceProvider::class,
            SupportServiceProvider::class,
            TranslatableServiceProvider::class,
            MetafieldsServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        Schema::create('test_metafield_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $driver = Schema::getConnection()->getDriverName();
        Schema::create('test_batch_string_metafield_owners', function (Blueprint $table) use ($driver): void {
            $this->defineBatchStringOwnerSchema($table, $driver);
        });

        Schema::create((new BatchSoftDeletingMetafieldOwner)->getTable(), static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes(BatchSoftDeletingMetafieldOwner::DELETED_AT);
        });
    }

    /** Define the native string owner fixture before the test transaction begins. */
    protected function defineBatchStringOwnerSchema(Blueprint $table, string $driver): void
    {
        $id = $table->string('id')->primary();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $id->collation('utf8mb4_bin');
        }
        $table->string('name');
        $table->timestamps();
        $table->softDeletes();
    }
}
