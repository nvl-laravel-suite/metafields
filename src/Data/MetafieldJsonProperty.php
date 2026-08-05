<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class MetafieldJsonProperty extends Data
{
    use DataTransform;

    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[TypeScriptType(MetafieldJsonPropertyTypeEnum::class)]
        public readonly MetafieldJsonPropertyTypeEnum $type,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRequired = false,
    ) {}
}
