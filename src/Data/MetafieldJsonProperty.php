<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Typed requiredness and scalar shape for one metafield JSON property.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class MetafieldJsonProperty extends Data
{
    use DataTransform;

    public function __construct(
        public readonly string $key,
        public readonly MetafieldJsonPropertyTypeEnum $type,
        public readonly bool $isRequired = false,
    ) {}
}
