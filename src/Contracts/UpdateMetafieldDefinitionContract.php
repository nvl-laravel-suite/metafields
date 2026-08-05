<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Data\UpdateMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Updates a metafield definition through a revision-aware mutation contract.
 */
interface UpdateMetafieldDefinitionContract
{
    /**
     * Update a metafield definition and its assignment.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): MetafieldDefinition;
}
