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

/**
 * Consumer-safe display contract for a metafield definition.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class MetafieldDefinitionPayload extends Data
{
    use DataTransform;

    /**
     * @param  string[]|null  $validationRules
     * @param  DataCollection<int, MetafieldJsonProperty>|null  $jsonPropertySchema
     * @param  array<string, array<string, mixed>>|null  $translations
     */
    public function __construct(
        public readonly string $namespace,
        public readonly string $key,
        public readonly MetafieldTypeEnum $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $hint,
        public readonly ?string $referencedModelType,
        public readonly bool $isTranslatable,
        public readonly bool $isRequired,
        public readonly bool $isFilterable,
        public readonly ?array $validationRules,
        #[DataCollectionOf(MetafieldJsonProperty::class)]
        public readonly ?DataCollection $jsonPropertySchema,
        public readonly mixed $defaultValue,
        public readonly int $displayOrder,
        public readonly int $revision,
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
