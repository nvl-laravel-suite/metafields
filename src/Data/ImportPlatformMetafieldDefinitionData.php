<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Spatie\LaravelData\Data;

/** Validated tenant import request without any client-supplied ownership field. */
final class ImportPlatformMetafieldDefinitionData extends Data
{
    /**
     * @param  array<string, string>  $referenceMap  Source reference ID to target reference ID.
     */
    public function __construct(
        public readonly string $grantId,
        public readonly int $expectedGrantRevision,
        public readonly int $expectedSourceRevision,
        public readonly string $idempotencyKey,
        public readonly string $namespace,
        public readonly string $key,
        public readonly AssignMetafieldDefinitionPayload $assignment,
        public readonly array $referenceMap = [],
    ) {}
}
