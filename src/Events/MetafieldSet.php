<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** A committed metafield identity and revision without its stored value.
 *
 * @api
 */
final readonly class MetafieldSet implements DomainEvent, OwnershipQueuedJob
{
    private readonly OwnershipJobEnvelope $ownershipEnvelope;

    /** Return the scalar authority captured when this fact occurred. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    /** Capture the persisted owner and definition identities. */
    public function __construct(
        public string $metafieldId,
        public string $ownerType,
        public int|string $ownerId,
        public string $definitionId,
        public int $revision,
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
