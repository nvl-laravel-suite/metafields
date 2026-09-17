<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

/** Boots the explicit platform-definition copy profile. */
abstract class MetafieldCatalogTenancyTestCase extends MetafieldTenancyTestCase
{
    /** Enable catalog-copy structure before provider boot. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('tenancy.sharing.metafields', 'copy');
    }
}
