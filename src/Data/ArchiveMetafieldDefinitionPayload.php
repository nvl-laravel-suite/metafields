<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Revision-aware archive or restore request for a definition.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class ArchiveMetafieldDefinitionPayload extends Data
{
    use DataTransform;

    public function __construct(
        public readonly bool $archived,
        public readonly int $expectedRevision,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'archived' => ['required', 'boolean'],
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
