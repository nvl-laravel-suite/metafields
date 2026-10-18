<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\PivotOwnershipDefinition;

abstract class OwnershipPivotTestCase extends OwnershipTestCase
{
    /** @return class-string<MetafieldDefinition> */
    protected function definitionClass(): string
    {
        return PivotOwnershipDefinition::class;
    }
}
