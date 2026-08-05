<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Metafields and its runtime dependencies in an isolated Laravel application.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DataServiceProvider::class,
            SupportServiceProvider::class,
            TranslatableServiceProvider::class,
            MetafieldsServiceProvider::class,
        ];
    }
}
