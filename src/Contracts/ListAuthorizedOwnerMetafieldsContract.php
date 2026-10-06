<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Data\OwnerMetafieldField;

/**
 * Defines the supported list authorized owner metafields workflow.
 *
 * @api
 */
interface ListAuthorizedOwnerMetafieldsContract
{
    /**
     * Return locale-resolved owner fields after authorizing storage access.
     *
     * @return Collection<int, OwnerMetafieldField>
     */
    public function execute(Model $owner, ?string $locale = null): Collection;
}
