<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Nvl\Metafields\Models\Metafield;

final class MetafieldsSyncedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  Collection<int, Metafield>  $metafields
     */
    public function __construct(public Model $owner, public Collection $metafields) {}
}
