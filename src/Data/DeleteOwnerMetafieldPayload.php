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
 * Revision-aware request for clearing one owner metafield value.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class DeleteOwnerMetafieldPayload extends Data
{
    use DataTransform;

    public function __construct(
        public readonly int $expectedRevision,
    ) {}

    /**
     * Return owner-value deletion validation rules.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'expectedRevision' => ['required', 'integer', 'min:1'],
        ];
    }
}
