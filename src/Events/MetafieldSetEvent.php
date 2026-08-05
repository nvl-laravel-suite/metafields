<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Nvl\Metafields\Models\Metafield;

final class MetafieldSetEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Metafield $metafield) {}
}
