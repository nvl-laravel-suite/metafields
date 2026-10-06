<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Defines the supported import platform metafield definition workflow.
 *
 * @api
 */
interface ImportPlatformMetafieldDefinitionContract
{
    /** Import the exact requested grant/source revision atomically. */
    public function execute(ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition;
}
