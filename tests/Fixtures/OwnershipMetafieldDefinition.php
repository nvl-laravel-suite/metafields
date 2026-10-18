<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Configured native owner-column definition root. */
class OwnershipMetafieldDefinition extends MetafieldDefinition
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }
}
