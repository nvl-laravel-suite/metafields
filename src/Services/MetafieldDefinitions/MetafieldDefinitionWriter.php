<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Translatable\Services\RelatedTranslationStore;

/**
 * Persists metafield definitions and their localized copy within the complete service transaction.
 *
 * @internal
 *
 * @phpstan-import-type DefinitionMutation from MetafieldDefinitionInput
 */
final readonly class MetafieldDefinitionWriter
{
    private const string OMITTED = '__metafield_omitted__';

    /**
     * Create the definition writer.
     */
    public function __construct(
        private RelatedTranslationStore $translations,
        private OwnershipWriter $ownershipWriter,
    ) {}

    /**
     * Create a metafield definition and optional localized copy.
     *
     * @param  DefinitionMutation  $data
     */
    public function create(array $data): MetafieldDefinition
    {
        return $this->createImported($data, []);
    }

    /**
     * Create a definition with server-owned immutable provenance attributes.
     *
     * @param  array<string, bool|int|string|null>  $provenance
     * @param  DefinitionMutation  $data
     */
    public function createImported(array $data, array $provenance): MetafieldDefinition
    {
        $definition = $this->ownershipWriter->create(MetafieldModelRegistry::modelClass(), function (array $ownership) use ($data, $provenance): MetafieldDefinition {
            $definition = MetafieldModelRegistry::newInstance($this->payload($data, true));
            $definition->forceFill([...$provenance, ...$ownership]);
            $this->syncDefaultValue($definition, $data);
            $definition->save();

            return $definition;
        });
        $this->syncTranslations($definition, $data);

        return $definition->refresh();
    }

    /**
     * Update a metafield definition and patch optional localized copy.
     *
     * @param  DefinitionMutation  $data
     */
    public function update(
        MetafieldDefinition $definition,
        array $data,
    ): MetafieldDefinition {
        $definition->fill($this->payload($data, false));
        $this->syncDefaultValue($definition, $data);
        $definition->revision++;
        $definition->save();
        $this->syncTranslations($definition, $data);

        return $definition->refresh()->load('translations');
    }

    /**
     * Build locale-neutral definition attributes.
     *
     *
     * @param  DefinitionMutation  $data
     * @return array<string, mixed>
     */
    private function payload(array $data, bool $creating): array
    {
        $payload = [
            'namespace' => $data['namespace'],
            'key' => $data['key'],
            'type' => $data['type'],
            'referenced_model_type' => in_array($data['type'], [
                MetafieldTypeEnum::Reference,
                MetafieldTypeEnum::ReferenceList,
            ], true)
                ? ($data['referencedModelType'] ?? null)
                : null,
        ];

        $this->putOptional($payload, 'is_translatable', $data['isTranslatable'] ?? self::OMITTED, false, $creating);
        $this->putOptional($payload, 'is_required', $data['isRequired'] ?? self::OMITTED, false, $creating);
        $this->putOptional($payload, 'is_filterable', $data['isFilterable'] ?? self::OMITTED, false, $creating);
        $this->putOptional($payload, 'display_order', $data['displayOrder'] ?? self::OMITTED, 0, $creating);

        if ($data['type'] === MetafieldTypeEnum::Json) {
            $payload['validation_rules'] = null;
            $this->putOptional(
                $payload,
                'json_property_schema',
                array_key_exists('jsonPropertySchema', $data) ? $data['jsonPropertySchema'] : self::OMITTED,
                null,
                $creating,
            );
        } else {
            $payload['json_property_schema'] = null;
            $this->putOptional(
                $payload,
                'validation_rules',
                array_key_exists('validationRules', $data) ? $data['validationRules'] : self::OMITTED,
                null,
                $creating,
            );
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== self::OMITTED,
        );
    }

    /**
     * Synchronize the locale-neutral default for non-translatable definitions.
     *
     * @param  DefinitionMutation  $data
     */
    private function syncDefaultValue(
        MetafieldDefinition $definition,
        array $data,
    ): void {
        $isTranslatable = array_key_exists('isTranslatable', $data) ? $data['isTranslatable'] : $definition->is_translatable;

        if ($isTranslatable) {
            $definition->clearDefaultValue();

            return;
        }

        if (! array_key_exists('defaultValue', $data)) {
            return;
        }

        $definition->setDefaultValue($data['defaultValue']);
    }

    /**
     * Store an optional mutation value without resetting omitted update fields.
     *
     * @param  array<string, mixed>  $payload
     */
    private function putOptional(
        array &$payload,
        string $key,
        mixed $value,
        mixed $default,
        bool $creating,
    ): void {
        if ($value === self::OMITTED) {
            if ($creating) {
                $payload[$key] = $default;
            }

            return;
        }

        $payload[$key] = $value;
    }

    /**
     * Patch localized definition copy and defaults when supplied.
     *
     * @param  DefinitionMutation  $data
     */
    private function syncTranslations(
        MetafieldDefinition $definition,
        array $data,
    ): void {
        if (! array_key_exists('translations', $data)) {
            return;
        }

        /** @var array<string, array<string, mixed>> $translations */
        $translations = collect($data['translations'])
            ->map(function (array $translation) use ($definition): array {
                $attributes = [];

                foreach (['title', 'description', 'hint', 'properties'] as $field) {
                    if (array_key_exists($field, $translation)) {
                        $attributes[$field] = $translation[$field];
                    }
                }

                if (array_key_exists('defaultValue', $translation)) {
                    $defaultValue = $translation['defaultValue'];
                    $attributes['default_value'] = $defaultValue === null
                        ? null
                        : $this->serializeStoredValue(
                            $definition->type->storeCast($defaultValue),
                        );
                }

                return $attributes;
            })
            ->all();

        $translationDefinition = $definition->translationDefinition();
        foreach ($translations as $locale => $attributes) {
            $this->translations->upsert($definition, $translationDefinition, $locale, $attributes);
        }
    }

    /**
     * Serialize a localized default for the text persistence column.
     */
    private function serializeStoredValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
