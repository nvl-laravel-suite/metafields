<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

/**
 * Raised when a mutation attempts to overwrite a newer definition or value.
 */
final class StaleMetafieldVersionException extends MetafieldException
{
    public static function forResource(string $resource, string $id): self
    {
        return new self(
            "The {$resource} [{$id}] changed after it was read; reload it before saving.",
        );
    }
}
