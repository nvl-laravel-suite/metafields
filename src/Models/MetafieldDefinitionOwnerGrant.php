<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Recipient-owned capability to copy one central definition revision. @api */
final class MetafieldDefinitionOwnerGrant extends MetafieldDefinitionTenantGrant
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }
}
