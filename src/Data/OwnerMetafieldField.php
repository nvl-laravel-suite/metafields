<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Support\MetafieldValueSerializer;
use Nvl\Translatable\Services\TranslationResolver;
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
     * Project only the canonical graph already admitted by the package batch reader.
     *
     * @internal
     */
    public static function fromBatchAssignment(
        MetafieldDefinitionAssignment $assignment,
        ?Metafield $metafield,
        ?string $locale = null,
    ): self {
        $definition = $assignment->definition;
        if (! $definition instanceof MetafieldDefinition || ! $definition->relationLoaded('translations')
            || ($metafield instanceof Metafield && ! $metafield->relationLoaded('translations'))) {
            throw new InvalidArgumentException('Batch field definitions and values require preloaded translations.');
        }
        $defaultRaw = $definition->type === MetafieldTypeEnum::Reference
            ? $definition->default_referenced_id
            : ($definition->is_translatable ? self::batchTranslation($definition, 'default_value', $locale) : $definition->default_value);
        $default = $definition->type->cast($defaultRaw);
        $raw = $metafield instanceof Metafield
            ? ($definition->type === MetafieldTypeEnum::Reference ? $metafield->referenced_id
                : ($definition->is_translatable ? self::batchTranslation($metafield, 'value', $locale) : $metafield->value))
            : $defaultRaw;
        $title = self::batchTranslation($definition, 'title', $locale);
        $description = self::batchTranslation($definition, 'description', $locale);
        $hint = self::batchTranslation($definition, 'hint', $locale);
        $translations = [];
        if ($definition->is_translatable && $metafield instanceof Metafield) {
            $rows = $metafield->getRelation('translations');
            if (! $rows instanceof Collection) {
                throw new InvalidArgumentException('Batch value translations must be preloaded.');
            }
            foreach ($rows as $translation) {
                if ($translation instanceof MetafieldTranslation) {
                    $translations[$translation->locale] = MetafieldValueSerializer::serialize($definition->type, $definition->type->cast($translation->value));
                }
            }
        }

        return new self(
            definitionId: $definition->id,
            handle: $definition->handle,
            namespace: $definition->namespace,
            key: $definition->key,
            type: $definition->type,
            title: is_string($title) && $title !== '' ? $title : $definition->handle,
            description: is_string($description) ? $description : null,
            hint: is_string($hint) ? $hint : null,
            isTranslatable: $definition->is_translatable,
            isRequired: $assignment->is_required || $definition->is_required,
            hasStoredValue: $metafield instanceof Metafield,
            usesDefaultValue: ! ($metafield instanceof Metafield) && $defaultRaw !== null
                && ($definition->type !== MetafieldTypeEnum::Reference || $defaultRaw !== ''),
            value: MetafieldValueSerializer::serialize($definition->type, $definition->type->cast($raw)),
            translations: $translations === [] ? null : $translations,
            jsonPropertySchema: is_array($definition->json_property_schema)
                ? MetafieldJsonProperty::collect($definition->json_property_schema, DataCollection::class) : null,
            defaultValue: $default instanceof DateTimeInterface ? $default->format('Y-m-d H:i:s') : $default,
            displayOrder: $assignment->display_order,
        );
    }

    /** Resolve a loaded admitted locale row without invoking canonical-record assertion queries. */
    private static function batchTranslation(Metafield|MetafieldDefinition $record, string $field, ?string $locale): mixed
    {
        $rows = $record->getRelation('translations');
        if (! $rows instanceof Collection) {
            throw new InvalidArgumentException('Batch translations must be preloaded.');
        }
        $models = $rows->filter(static fn (mixed $row): bool => $row instanceof Model)->values();
        $options = $record->translationDefinition();
        $requested = $options->assertLocale($locale ?? $record->getCurrentLocale());
        $available = [];
        foreach ($models as $model) {
            $rowLocale = $model->getAttribute($options->localeKey);
            if (is_string($rowLocale)) {
                $available[] = $rowLocale;
            }
        }

        return (new TranslationResolver)->resolve($models, $options, $field, $requested, $options->localeChain($requested, $available))->value;
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
