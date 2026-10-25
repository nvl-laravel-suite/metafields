<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Spatie\LaravelData\Data;

/**
 * One immutable read-only installation diagnostic.
 */
final class MetafieldDoctorCheckData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly bool $passed,
        public readonly string $message,
    ) {}
}
