<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Enums\SystemActor;

/**
 * Consumer-owned authorization boundary for native owner reads and operations.
 *
 * @api
 */
interface MetafieldAuthorization
{
    public function authorizeDefinition(
        Authenticatable|SystemActor $actor,
        MetafieldAbility $ability,
        ?MetafieldDefinition $definition = null,
    ): void;

    public function authorizeOwner(
        Authenticatable|SystemActor $actor,
        MetafieldAbility $ability,
        ?Model $owner = null,
        ?MetafieldDefinition $definition = null,
    ): void;
}
