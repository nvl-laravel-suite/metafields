<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Tests\OwnershipTestCase;

/** Exercises repeated preparation on one physically prefixed source. */
class MetafieldsPrefixedOwnershipTestCase extends OwnershipTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.connections.sqlite.prefix', 'review_');
    }
}
