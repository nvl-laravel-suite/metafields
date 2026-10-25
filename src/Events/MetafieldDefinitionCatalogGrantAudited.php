<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Contracts\DomainEvent;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Scalar audit fact for a committed native definition grant lifecycle change. @api */
final readonly class MetafieldDefinitionCatalogGrantAudited implements DomainEvent, OwnershipQueuedJob
{
    private OwnershipJobEnvelope $ownershipEnvelope;

    public function __construct(
        public string $operation,
        public string $grantId,
        public string $ownerScopeKey,
        public string $definitionId,
        public int $sourceRevision,
        public int $grantRevision,
        public int $schemaVersion = 1,
    ) {
        $this->ownershipEnvelope = MetafieldOwnership::envelope();
    }

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownershipEnvelope;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
