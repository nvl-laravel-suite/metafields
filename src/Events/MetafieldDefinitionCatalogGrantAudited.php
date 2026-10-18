<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Scalar-only audit fact for a committed definition grant lifecycle change. *
 * @api
 */
final readonly class MetafieldDefinitionCatalogGrantAudited implements DomainEvent, OwnershipQueuedJob
{
    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /** Create the committed audit fact. */
    public function __construct(
        public string $operation,
        public string $grantId,
        public string $tenantId,
        public string $definitionId,
        public int $sourceRevision,
        public int $grantRevision,
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = MetafieldOwnership::envelope();
    }

    /** Return the immutable event payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
