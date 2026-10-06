<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Support\Contracts\DomainEvent;

/** The exact synchronized metafield identities for one persisted owner.
 *
 * @api
 */
final readonly class MetafieldsSynced implements DomainEvent
{
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
    ) {}

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
