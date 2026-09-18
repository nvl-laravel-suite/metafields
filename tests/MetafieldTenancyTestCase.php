<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyDirectory;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyFixtureServiceProvider;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyMaintenanceMode;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyPlatformAccess;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Providers\TenancyServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;

/** Boots Metafields' non-transactional tenancy adoption fixture. */
abstract class MetafieldTenancyTestCase extends Orchestra
{
    use DatabaseMigrations;

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

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            SupportServiceProvider::class,
            TenancyServiceProvider::class,
            MetafieldTenancyFixtureServiceProvider::class,
            TranslatableServiceProvider::class,
            MetafieldsServiceProvider::class,
        ];
    }

    /** Configure tenancy structurally before package providers boot. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'cache.default' => 'array',
            'metafields.migrations.enabled' => true,
            'metafields.owners.test-owner' => [
                'model' => TestMetafieldOwner::class,
                'label' => 'Test owners',
                'supported_types' => array_map(
                    static fn (MetafieldTypeEnum $type): string => $type->value,
                    MetafieldTypeEnum::cases(),
                ),
                'sections' => ['general'],
                'runtime_status' => 'live',
            ],
            'metafields.reference_models.test-owner' => TestMetafieldOwner::class,
            'translatable.locales' => ['en', 'bg'],
            'translatable.fallback_locales' => ['en'],
            'tenancy.enabled' => true,
            'tenancy.resources.metafields' => 'tenant',
            'tenancy.sharing.metafields' => 'none',
            'tenancy.directory.driver' => 'host',
            'tenancy.directory.adapter' => null,
            'tenancy.access.platform' => null,
        ]);
        $app->instance(MaintenanceMode::class, new MetafieldTenancyMaintenanceMode);
        $app->instance(TenantDirectory::class, new MetafieldTenancyDirectory);
        $app->instance(PlatformAccess::class, new MetafieldTenancyPlatformAccess);
    }

    /** Load only the opt-in core schema after Testbench refreshes normal migrations. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $provider = new ReflectionClass(TenancyServiceProvider::class);
        $this->loadMigrationsFrom(dirname($provider->getFileName()).'/../../database/migrations/tenancy');
    }
}
