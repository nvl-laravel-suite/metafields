<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class AssignMetafieldDefinitionPayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|Optional|null  $uiConfig
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $ownerType,
        #[LiteralTypeScriptType('string')]
        public readonly string $section,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int $displayOrder = 0,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRequired = false,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isActive = true,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $uiConfig = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'ownerType' => ['required', 'string', Rule::in(array_keys((array) config('metafields.owners', [])))],
            'section' => ['required', 'string', 'max:255'],
            'displayOrder' => ['nullable', 'integer', 'min:0'],
            'isRequired' => ['boolean'],
            'isActive' => ['boolean'],
            'uiConfig' => ['nullable', 'array'],
        ];
    }
}
