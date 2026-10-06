<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Data\OwnersMetafields;

/**
 * Bounded authorized fields for persisted owners.
 *
 * @api
 */
interface ListAuthorizedOwnersMetafieldsContract
{
    /** @param list<Model> $owners */
    public function execute(array $owners, ?string $locale = null): OwnersMetafields;
}
