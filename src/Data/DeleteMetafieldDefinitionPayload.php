<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class DeleteMetafieldDefinitionPayload extends Data
{
    use DataTransform;

    public function __construct(
        public readonly int $expectedRevision,
        public readonly bool $deleteValues = false,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'deleteValues' => ['sometimes', 'boolean'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
