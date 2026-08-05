<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldFieldCatalog;

/**
 * List metafield values for a given owner model with optional locale scoping.
 */
final class ListOwnerMetafieldsAction
{
    public function __construct(
        private readonly OwnerMetafieldFieldCatalog $catalog,
    ) {}

    /**
     * List current field data for an owner.
     *
     * @param  Model  $owner  Owner model whose assigned fields are listed
     * @param  string|null  $locale  Optional locale for translatable values
     * @return Collection<int, OwnerMetafieldField>
     */
    public function execute(Model $owner, ?string $locale = null): Collection
    {
        return $this->catalog->list($owner, $locale);
    }
}
