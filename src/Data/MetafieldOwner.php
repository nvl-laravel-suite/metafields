<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * MetafieldOwner: output DTO describing one supported owner registry entry.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class MetafieldOwner extends Data
{
    use DataTransform;

    /**
     * @param  list<string>  $supportedTypes
     * @param  list<string>  $sections
     */
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly array $supportedTypes,
        public readonly array $sections,
        public readonly string $runtimeStatus,
        public readonly bool $supportsRuntimeEditing,
    ) {}
}
