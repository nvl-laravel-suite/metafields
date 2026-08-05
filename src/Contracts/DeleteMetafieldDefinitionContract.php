<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Deletes a metafield definition through a revision-aware contract.
 */
interface DeleteMetafieldDefinitionContract
{
    /**
     * Delete a definition and optionally its stored values.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        int $expectedRevision,
        bool $deleteValues = false,
    ): bool;
}
