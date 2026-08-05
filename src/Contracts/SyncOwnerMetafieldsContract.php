<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Models\Metafield;

interface SyncOwnerMetafieldsContract
{
    /**
     * @return Collection<int, Metafield>
     */
    public function execute(Model $owner, SyncOwnerMetafieldsPayload $data): Collection;
}
