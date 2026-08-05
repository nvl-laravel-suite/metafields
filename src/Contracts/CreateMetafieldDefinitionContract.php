<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Creates a metafield definition from the validated creation contract.
 */
interface CreateMetafieldDefinitionContract
{
    /**
     * Create a metafield definition and its assignment.
     */
    public function execute(CreateMetafieldDefinitionPayload $data): MetafieldDefinition;
}
