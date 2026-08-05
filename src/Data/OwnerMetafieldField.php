<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Support\MetafieldValueSerializer;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** OwnerMetafieldField: assigned owner-metafield definition with current owner value state. */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class OwnerMetafieldField extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|null  $translations
     * @param  DataCollection<int, MetafieldJsonProperty>|null  $jsonPropertySchema
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $definitionId,
        #[LiteralTypeScriptType('string')]
        public readonly string $handle,
        #[LiteralTypeScriptType('string')]
        public readonly string $namespace,
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        public readonly MetafieldTypeEnum $type,
        #[LiteralTypeScriptType('string')]
        public readonly string $title,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $description,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $hint,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isTranslatable,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRequired,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $hasStoredValue,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $usesDefaultValue,
        #[LiteralTypeScriptType('unknown | null')]
        public readonly mixed $value,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $translations,
        #[DataCollectionOf(MetafieldJsonProperty::class)]
        public readonly ?DataCollection $jsonPropertySchema,
        #[LiteralTypeScriptType('unknown | null')]
        public readonly mixed $defaultValue,
        #[LiteralTypeScriptType('number')]
        public readonly int $displayOrder,
    ) {}

    public static function fromAssignment(
        MetafieldDefinitionAssignment $assignment,
        ?Metafield $metafield,
        ?string $locale = null,
    ): self {
        $definition = $assignment->definition;

        if (! $definition instanceof MetafieldDefinition) {
            throw new InvalidArgumentException('Metafield assignment definition must be loaded.');
        }

        $resolvedValue = $metafield instanceof Metafield
            ? match ($definition->type) {
                MetafieldTypeEnum::Reference => $metafield->referenced_id,
                MetafieldTypeEnum::ReferenceList => $definition->type->cast($metafield->value),
                default => $metafield->getValue($locale),
            }
        : $definition->getDefaultValue($locale);

        return new self(
            definitionId: $definition->id,
            handle: $definition->handle,
            namespace: $definition->namespace,
            key: $definition->key,
            type: $definition->type,
            title: $definition->displayTitle($locale),
            description: $definition->displayDescription($locale),
            hint: $definition->displayHint($locale),
            isTranslatable: $definition->is_translatable,
            isRequired: $assignment->is_required || $definition->is_required,
            hasStoredValue: $metafield instanceof Metafield,
            usesDefaultValue: ! ($metafield instanceof Metafield) && $definition->hasDefaultValue(),
            value: MetafieldValueSerializer::serialize($definition->type, $resolvedValue),
            translations: $definition->is_translatable
                ? self::serializeTranslations($definition, $metafield)
                : null,
            jsonPropertySchema: is_array($definition->json_property_schema)
                ? MetafieldJsonProperty::collect($definition->json_property_schema, DataCollection::class)
                : null,
            defaultValue: $definition->getSerializableDefaultValue($locale),
            displayOrder: $assignment->display_order,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function serializeTranslations(
        MetafieldDefinition $definition,
        ?Metafield $metafield,
    ): ?array {
        if (! $metafield instanceof Metafield) {
            return null;
        }

        $metafield->loadMissing('translations');

        $translations = $metafield->translations
            ->filter(static fn (mixed $translation): bool => $translation instanceof MetafieldTranslation)
            ->mapWithKeys(
                static fn (MetafieldTranslation $translation): array => [
                    $translation->locale => MetafieldValueSerializer::serialize(
                        $definition->type,
                        $definition->type->cast($translation->value),
                    ),
                ],
            )
            ->all();

        return $translations === [] ? null : $translations;
    }
}
