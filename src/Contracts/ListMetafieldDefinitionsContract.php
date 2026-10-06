<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Support\Collection;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Defines the supported list metafield definitions workflow.
 *
 * @api
 */
interface ListMetafieldDefinitionsContract
{
    /**
     * @return Collection<int, MetafieldDefinition>
     */
    public function execute(): Collection;
}
