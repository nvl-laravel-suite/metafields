<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** MetafieldOwner: output DTO describing one supported owner registry entry. */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class MetafieldOwner extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $supportedTypes
     * @param  list<string>  $sections
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $type,
        #[LiteralTypeScriptType('string')]
        public readonly string $label,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $supportedTypes,
        #[LiteralTypeScriptType('string[]')]
        public readonly array $sections,
        #[LiteralTypeScriptType("'live' | 'planned'")]
        public readonly string $runtimeStatus,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $supportsRuntimeEditing,
    ) {}
}
