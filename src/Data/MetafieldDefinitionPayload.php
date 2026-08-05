<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Database\Eloquent\Model;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Consumer-safe display contract for a metafield definition.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class MetafieldDefinitionPayload extends Data
{
    use DataTransform;

    /**
     * @param  string[]|null  $validationRules
     * @param  DataCollection<int, MetafieldJsonProperty>|null  $jsonPropertySchema
     * @param  array<string, array<string, mixed>>|null  $translations
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $namespace,
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[TypeScriptType(MetafieldTypeEnum::class)]
        public readonly MetafieldTypeEnum $type,
        #[LiteralTypeScriptType('string')]
        public readonly string $title,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $description,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $hint,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $referencedModelType,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isTranslatable,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRequired,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isFilterable,
        #[LiteralTypeScriptType('string[] | null')]
        public readonly ?array $validationRules,
        #[DataCollectionOf(MetafieldJsonProperty::class)]
        public readonly ?DataCollection $jsonPropertySchema,
        #[LiteralTypeScriptType('unknown | null')]
        public readonly mixed $defaultValue,
        #[LiteralTypeScriptType('number')]
        public readonly int $displayOrder,
        #[LiteralTypeScriptType('number')]
        public readonly int $revision,
        #[LiteralTypeScriptType('Record<string, { title: string; description: string | null; hint: string | null; defaultValue: unknown; properties: Record<string, unknown> | null }> | null')]
        public readonly ?array $translations,
    ) {}

    /**
     * Build a display payload from a definition and any loaded translations.
     */
    public static function fromModel(MetafieldDefinition $definition): self
    {
        return new self(
            namespace: $definition->namespace,
            key: $definition->key,
            type: $definition->type,
            title: $definition->displayTitle(),
            description: $definition->displayDescription(),
            hint: $definition->displayHint(),
            referencedModelType: $definition->referenced_model_type,
            isTranslatable: $definition->is_translatable,
            isRequired: $definition->is_required,
            isFilterable: $definition->is_filterable,
            validationRules: $definition->validation_rules,
            jsonPropertySchema: is_array($definition->json_property_schema)
                ? MetafieldJsonProperty::collect($definition->json_property_schema, DataCollection::class)
                : null,
            defaultValue: $definition->getSerializableDefaultValue(),
            displayOrder: $definition->display_order,
            revision: $definition->revision,
            translations: self::translationsFromModel($definition),
        );
    }

    /**
     * Return loaded definition translations keyed by locale.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private static function translationsFromModel(MetafieldDefinition $definition): ?array
    {
        if (! $definition->relationLoaded('translations')) {
            return null;
        }

        /** @var array<string, array<string, mixed>> $translations */
        $translations = $definition->translations
            ->mapWithKeys(static function (Model $translation) use ($definition): array {
                if (! $translation instanceof MetafieldDefinitionTranslation) {
                    return [];
                }

                return [
                    $translation->locale => [
                        'title' => $translation->title,
                        'description' => $translation->description,
                        'hint' => $translation->hint,
                        'defaultValue' => $definition->type->cast($translation->default_value),
                        'properties' => $translation->properties,
                    ],
                ];
            })
            ->all();

        return $translations === [] ? null : $translations;
    }
}
