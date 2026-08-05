<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Data\MetafieldDefinitionMutationPayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Translatable\Services\TranslationWriter;
use Spatie\LaravelData\Optional;

/**
 * Persists metafield definitions and their localized copy within an action-owned transaction.
 */
final readonly class MetafieldDefinitionWriter
{
    /**
     * Create the definition writer.
     */
    public function __construct(
        private TranslationWriter $translations,
    ) {}

    /**
     * Create a metafield definition and optional localized copy.
     */
    public function create(MetafieldDefinitionMutationPayload $data): MetafieldDefinition
    {
        $definition = new MetafieldDefinition($this->payload($data, true));
        $this->syncDefaultValue($definition, $data);
        $definition->save();
        $this->syncTranslations($definition, $data);

        return $definition->load('translations');
    }

    /**
     * Update a metafield definition and patch optional localized copy.
     */
    public function update(
        MetafieldDefinition $definition,
        MetafieldDefinitionMutationPayload $data,
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
     * @return array<string, mixed>
     */
    private function payload(MetafieldDefinitionMutationPayload $data, bool $creating): array
    {
        $payload = [
            'namespace' => $data->namespace,
            'key' => $data->key,
            'type' => $data->type,
            'referenced_model_type' => in_array($data->type, [
                MetafieldTypeEnum::Reference,
                MetafieldTypeEnum::ReferenceList,
            ], true)
                ? ($data->referencedModelType instanceof Optional
                    ? ($creating ? null : $data->referencedModelType)
                    : $data->referencedModelType)
                : null,
        ];

        $this->putOptional($payload, 'is_translatable', $data->isTranslatable, false, $creating);
        $this->putOptional($payload, 'is_required', $data->isRequired, false, $creating);
        $this->putOptional($payload, 'is_filterable', $data->isFilterable, false, $creating);
        $this->putOptional($payload, 'display_order', $data->displayOrder, 0, $creating);

        if ($data->type === MetafieldTypeEnum::Json) {
            $payload['validation_rules'] = null;
            $this->putOptional(
                $payload,
                'json_property_schema',
                $data->jsonPropertySchema instanceof Optional
                    ? $data->jsonPropertySchema
                    : $data->jsonPropertySchema?->toArray(),
                null,
                $creating,
            );
        } else {
            $payload['json_property_schema'] = null;
            $this->putOptional(
                $payload,
                'validation_rules',
                $data->validationRules,
                null,
                $creating,
            );
        }

        return array_filter(
            $payload,
            static fn (mixed $value): bool => ! $value instanceof Optional,
        );
    }

    /**
     * Synchronize the locale-neutral default for non-translatable definitions.
     */
    private function syncDefaultValue(
        MetafieldDefinition $definition,
        MetafieldDefinitionMutationPayload $data,
    ): void {
        $isTranslatable = $data->isTranslatable instanceof Optional
            ? $definition->is_translatable
            : $data->isTranslatable;

        if ($isTranslatable) {
            $definition->clearDefaultValue();

            return;
        }

        if ($data->defaultValue instanceof Optional) {
            return;
        }

        $definition->setDefaultValue($data->defaultValue);
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
        if ($value instanceof Optional) {
            if ($creating) {
                $payload[$key] = $default;
            }

            return;
        }

        $payload[$key] = $value;
    }

    /**
     * Patch localized definition copy and defaults when supplied.
     */
    private function syncTranslations(
        MetafieldDefinition $definition,
        MetafieldDefinitionMutationPayload $data,
    ): void {
        if ($data->translations instanceof Optional || $data->translations === null) {
            return;
        }

        /** @var array<string, array<string, mixed>> $translations */
        $translations = collect($data->translations)
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

        $this->translations->patch($definition, $translations);
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
