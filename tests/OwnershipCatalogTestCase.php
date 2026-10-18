<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\OwnershipCatalogDefinition;

abstract class OwnershipCatalogTestCase extends OwnershipTestCase
{
    /** @return class-string<MetafieldDefinition> */
    protected function definitionClass(): string
    {
        return OwnershipCatalogDefinition::class;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-ownership.central_operations', ['metafields-maintain']);
    }
}
