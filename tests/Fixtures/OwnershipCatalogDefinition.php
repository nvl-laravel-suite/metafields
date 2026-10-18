<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

/** Explicitly opts into separately granted central schema copies. */
class OwnershipCatalogDefinition extends OwnershipMetafieldDefinition
{
    public static function allowsCentralCatalog(): bool
    {
        return true;
    }
}
