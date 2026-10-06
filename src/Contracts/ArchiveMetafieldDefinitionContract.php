<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Data\ArchiveMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Defines the supported archive metafield definition workflow.
 *
 * @api
 */
interface ArchiveMetafieldDefinitionContract
{
    /**
     * Archive or restore a definition through its expected revision.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        ArchiveMetafieldDefinitionPayload $data,
    ): MetafieldDefinition;
}
