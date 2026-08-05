<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Consumer-owned authorization boundary for optional Metafields HTTP routes.
 */
interface MetafieldAuthorization
{
    public function authorizeDefinition(
        MetafieldAbility $ability,
        ?MetafieldDefinition $definition = null,
    ): void;

    public function authorizeOwner(
        MetafieldAbility $ability,
        ?Model $owner = null,
        ?MetafieldDefinition $definition = null,
    ): void;
}
