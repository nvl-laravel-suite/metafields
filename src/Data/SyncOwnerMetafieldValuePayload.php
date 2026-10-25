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

/**
 * SyncOwnerMetafieldValuePayload: one owner-metafield mutation payload item.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class SyncOwnerMetafieldValuePayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|Optional|null  $translations
     */
    public function __construct(
        public readonly string $definitionId,
        public readonly bool|Optional $clear = false,
        public readonly mixed $value = null,
        public readonly array|Optional|null $translations = null,
        public readonly TranslationSyncMode $translationMode = TranslationSyncMode::Patch,
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
        return self::translatedMessages('nvl-metafields::owner-metafields');
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('nvl-metafields::owner-metafields');
    }
}
