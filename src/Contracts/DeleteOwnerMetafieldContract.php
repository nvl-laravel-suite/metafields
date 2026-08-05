<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Clears one owner metafield through a revision-aware mutation.
 */
interface DeleteOwnerMetafieldContract
{
    /**
     * Clear one owner metafield value.
     */
    public function execute(
        Model $owner,
        MetafieldDefinition|string $definition,
        int $expectedRevision,
    ): bool;
}
