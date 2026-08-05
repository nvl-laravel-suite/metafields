<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** SyncOwnerMetafieldsPayload: batch owner-metafield mutation payload. */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class SyncOwnerMetafieldsPayload extends Data
{
    use DataTransform;

    /**
     * @param  DataCollection<int, SyncOwnerMetafieldValuePayload>  $items
     */
    public function __construct(
        #[DataCollectionOf(SyncOwnerMetafieldValuePayload::class)]
        public readonly DataCollection $items,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'items' => [
                'required',
                'array',
                'min:1',
                'max:'.MetafieldConfiguration::positiveInteger(
                    'metafields.limits.maximum_sync_items',
                    100,
                ),
            ],
            'items.*' => ['array'],
            'items.*.definitionId' => ['required', 'uuid', 'distinct'],
            'items.*.clear' => ['sometimes', 'boolean'],
            'items.*.translations' => ['nullable', 'array', new SupportedLocaleMapRule],
            'items.*.translationMode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
            'items.*.expectedRevision' => ['nullable', 'integer', 'min:1'],
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
