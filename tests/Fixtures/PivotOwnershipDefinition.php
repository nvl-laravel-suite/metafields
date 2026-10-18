<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Consumer-provided exclusive primary ownership pivot. */
final class PivotOwnershipDefinition extends MetafieldDefinition
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::pivot(OwnershipCompany::class, 'metafield_definition_owners', 'definition_id', 'company_id');
    }
}
