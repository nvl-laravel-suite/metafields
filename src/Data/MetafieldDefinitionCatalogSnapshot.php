<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

/** Immutable scalar platform definition snapshot authorized by one grant. */
final readonly class MetafieldDefinitionCatalogSnapshot
{
    /** Create an authorized catalog snapshot. */
    public function __construct(
        public string $grantId,
        public int $grantRevision,
        public string $sourceId,
        public int $sourceRevision,
        public string $sourceHash,
        public MetafieldDefinitionPayload $definition,
    ) {}
}
