<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Data\OwnerMetafieldField;

/**
 * Defines the supported list owner metafields workflow.
 *
 * @api
 */
interface ListOwnerMetafieldsContract
{
    /**
     * List current field data for an owner.
     *
     * @param  Model  $owner  Owner model whose assigned fields are listed
     * @param  string|null  $locale  Optional locale for translatable values
     * @return Collection<int, OwnerMetafieldField>
     */
    public function execute(Model $owner, ?string $locale = null): Collection;
}
