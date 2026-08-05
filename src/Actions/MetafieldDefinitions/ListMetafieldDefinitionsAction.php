<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Illuminate\Support\Collection;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;

/**
 * List all registered metafield definitions from the catalog.
 */
final class ListMetafieldDefinitionsAction
{
    public function __construct(
        private readonly MetafieldDefinitionCatalog $catalog,
    ) {}

    /**
     * @return Collection<int, MetafieldDefinition>
     */
    public function execute(): Collection
    {
        return $this->catalog->list();
    }
}
