<?php

declare(strict_types=1);

namespace Nvl\Metafields\Events;

use Nvl\Support\Contracts\DomainEvent;

/** A committed metafield identity and revision without its stored value.
 *
 * @api
 */
final readonly class MetafieldSet implements DomainEvent
{
    /** Capture the persisted owner and definition identities. */
    public function __construct(
        public string $metafieldId,
        public string $ownerType,
        public int|string $ownerId,
        public string $definitionId,
        public int $revision,
        public int $schemaVersion = 1,
    ) {}

    /** Return the immutable payload schema version. */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }
}
