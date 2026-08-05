<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** SyncOwnerMetafieldValuePayload: one owner-metafield mutation payload item. */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class SyncOwnerMetafieldValuePayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|Optional|null  $translations
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $definitionId,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $clear = false,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('unknown | null')]
        public readonly mixed $value = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly array|Optional|null $translations = null,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType("'patch' | 'replace'")]
        public readonly TranslationSyncMode $translationMode = TranslationSyncMode::Patch,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number | null')]
        public readonly int|Optional|null $expectedRevision = null,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'definitionId' => ['required', 'uuid'],
            'clear' => ['sometimes', 'boolean'],
            'translations' => ['nullable', 'array', new SupportedLocaleMapRule],
            'translationMode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
            'expectedRevision' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('metafields::owner-metafields');
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('metafields::owner-metafields');
    }
}
