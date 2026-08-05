<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One immutable read-only installation diagnostic.
 */
#[TypeScript]
final class MetafieldDoctorCheckData extends Data
{
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[LiteralTypeScriptType("'error' | 'warning'")]
        public readonly string $severity,
        public readonly bool $passed,
        #[LiteralTypeScriptType('string')]
        public readonly string $message,
    ) {}
}
