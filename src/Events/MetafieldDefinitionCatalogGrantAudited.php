<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Scalar-only audit fact for a committed definition grant lifecycle change. */
final readonly class MetafieldDefinitionCatalogGrantAudited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Create the committed audit fact. */
    public function __construct(
        public string $operation,
        public string $grantId,
        public string $tenantId,
        public string $definitionId,
        public int $sourceRevision,
        public int $grantRevision,
    ) {}
}
