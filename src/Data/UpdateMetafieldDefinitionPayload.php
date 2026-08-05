<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Revision-aware contract for patching a metafield definition and localized copy.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
#[LiteralTypeScriptType("Omit<Nvl.Metafields.Data.MetafieldDefinitionMutationPayload, 'translations'> & { expectedRevision: number; translations?: Record<string, { title?: string; description?: string | null; hint?: string | null; defaultValue?: unknown; properties?: Record<string, unknown> | null }> | null }")]
final class UpdateMetafieldDefinitionPayload extends MetafieldDefinitionMutationPayload
{
    /**
     * @param  string[]|Optional|null  $validationRules
     * @param  DataCollection<int, MetafieldJsonProperty>|Optional|null  $jsonPropertySchema
     * @param  array<array-key, mixed>|bool|float|int|string|Optional|null  $defaultValue
     * @param  array<string, array<string, mixed>>|Optional|null  $translations
     */
    public function __construct(
        string $namespace,
        string $key,
        MetafieldTypeEnum $type,
        AssignMetafieldDefinitionPayload $assignment,
        public readonly int $expectedRevision,
        string|Optional|null $referencedModelType = new Optional,
        bool|Optional $isTranslatable = new Optional,
        bool|Optional $isRequired = new Optional,
        bool|Optional $isFilterable = new Optional,
        array|Optional|null $validationRules = new Optional,
        DataCollection|Optional|null $jsonPropertySchema = new Optional,
        array|bool|float|int|string|Optional|null $defaultValue = new Optional,
        int|Optional $displayOrder = new Optional,
        array|Optional|null $translations = null,
    ) {
        parent::__construct(
            namespace: $namespace,
            key: $key,
            type: $type,
            assignment: $assignment,
            referencedModelType: $referencedModelType,
            isTranslatable: $isTranslatable,
            isRequired: $isRequired,
            isFilterable: $isFilterable,
            validationRules: $validationRules,
            jsonPropertySchema: $jsonPropertySchema,
            defaultValue: $defaultValue,
            displayOrder: $displayOrder,
            translations: $translations,
        );
    }

    /**
     * Return update-specific validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            ...parent::rules(),
            'expectedRevision' => ['required', 'integer', 'min:1'],
            'translations' => ['sometimes', 'array', 'min:1', new SupportedLocaleMapRule],
            'translations.*.title' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
