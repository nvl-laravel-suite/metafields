<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * Loaded target facts for query-free policy admission; never serialized.
 *
 * @api
 */
final readonly class MetafieldReferenceFacts
{
    /** @param array<string, array<array-key, Model>> $targets */
    public function __construct(private array $targets) {}

    /** Return a canonical admitted target or explicit absence without querying. */
    public function find(string $registeredReference, string $id): ?Model
    {
        return $this->targets[$registeredReference][$id] ?? null;
    }
}
