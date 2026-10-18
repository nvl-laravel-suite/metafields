<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** The exact synchronized metafield identities for one persisted owner.
 *
 * @api
 */
final readonly class MetafieldsSynced implements DomainEvent, OwnershipQueuedJob
{
    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /**
     * Capture synchronized identifiers without retaining owner or metafield models.
     *
     * @param  list<string>  $metafieldIds
     */
    public function __construct(
        public string $ownerType,
        public int|string $ownerId,
        public array $metafieldIds,
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = MetafieldOwnership::envelope();
    }

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
