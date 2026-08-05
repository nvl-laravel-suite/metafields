<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use Nvl\Metafields\Data\UpdateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Support\MetafieldJsonPropertySchemaValidator;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;
use Spatie\LaravelData\Optional;

/**
 * Guards definition updates that would invalidate active owner values.
 */
final class MetafieldDefinitionMutationGuard
{
    /**
     * Reject updates that would invalidate defaults, translations, or active owner values.
     */
    public function ensureUpdateIsSafe(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): void {
        $this->ensureNewTranslationsHaveTitles($definition, $data);
        $this->ensureDefaultValuesAreSafe($definition, $data);

        if (! $this->hasActiveValues($definition)) {
            return;
        }

        $blockedChanges = $this->blockedShapeChanges($definition, $data);

        if ($blockedChanges === []) {
            return;
        }

        $messages = [];

        foreach ($blockedChanges as $field => $label) {
            $messages[$field] = [
                (string) trans('metafields::metafields/validation.custom.definition.active_values_shape_change', [
                    'field' => $label,
                ]),
            ];
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * Require a title only when an update introduces a previously unknown locale.
     */
    private function ensureNewTranslationsHaveTitles(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): void {
        if ($data->translations instanceof Optional || $data->translations === null) {
            return;
        }

        $definition->loadMissing('translations');
        $existingLocales = $definition->translations->pluck('locale')->all();
        $errors = [];

        foreach ($data->translations as $locale => $translation) {
            if (in_array($locale, $existingLocales, true)) {
                continue;
            }

            $title = $translation['title'] ?? null;

            if (! is_string($title) || trim($title) === '') {
                $errors["translations.{$locale}.title"] = [
                    (string) trans(
                        'metafields::metafields/validation.custom.translations.new_locale_title',
                    ),
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Preserve defaults only when they remain valid for the target definition shape.
     */
    private function ensureDefaultValuesAreSafe(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): void {
        $targetIsTranslatable = $this->targetIsTranslatable($definition, $data);
        $targetValidationRules = $this->targetValidationRules($definition, $data);
        $targetJsonPropertySchema = $this->targetJsonPropertySchemaForValidation($definition, $data);
        $targetReferencedModelType = $this->targetReferencedModelType($definition, $data);

        if (! $data->defaultValue instanceof Optional
            && $this->hasDefaultValue($data->defaultValue)) {
            if ($targetIsTranslatable) {
                $this->throwDefaultValidation(
                    'defaultValue',
                    'metafields::metafields/validation.custom.defaultValue.nonlocalized_storage',
                );
            }

            if (! $this->defaultValueIsValid(
                type: $data->type,
                validationRules: $targetValidationRules,
                jsonPropertySchema: $targetJsonPropertySchema,
                referencedModelType: $targetReferencedModelType,
                value: $data->defaultValue,
            )) {
                $this->throwDefaultValidation(
                    'defaultValue',
                    'metafields::metafields/validation.custom.defaultValue.invalid_type',
                );
            }
        }

        if (! $targetIsTranslatable
            && $data->defaultValue instanceof Optional
            && ! $definition->is_translatable
            && $definition->hasDefaultValue()
            && ! $this->defaultValueIsValid(
                type: $data->type,
                validationRules: $targetValidationRules,
                jsonPropertySchema: $targetJsonPropertySchema,
                referencedModelType: $targetReferencedModelType,
                value: $definition->getSerializableDefaultValue(),
            )) {
            $this->throwDefaultValidation(
                'defaultValue',
                'metafields::metafields/validation.custom.defaultValue.invalid_type',
            );
        }

        $definition->loadMissing('translations');
        /** @var array<string, mixed> $localizedDefaults */
        $localizedDefaults = $definition->translations
            ->filter(
                static fn (mixed $translation): bool => $translation instanceof MetafieldDefinitionTranslation
                    && $translation->default_value !== null,
            )
            ->mapWithKeys(
                static fn (MetafieldDefinitionTranslation $translation): array => [
                    $translation->locale => $translation->default_value,
                ],
            )
            ->all();

        if (! $data->translations instanceof Optional && is_array($data->translations)) {
            foreach ($data->translations as $locale => $translation) {
                if (! array_key_exists('defaultValue', $translation)) {
                    continue;
                }

                $localizedDefaults[$locale] = $translation['defaultValue'];

                if (! $targetIsTranslatable
                    && $this->hasDefaultValue($translation['defaultValue'])) {
                    $this->throwDefaultValidation(
                        "translations.{$locale}.defaultValue",
                        'metafields::metafields/validation.custom.translations.defaultValue.localized_storage',
                    );
                }
            }
        }

        if (! $targetIsTranslatable) {
            return;
        }

        foreach ($localizedDefaults as $locale => $localizedDefault) {
            if (! $this->hasDefaultValue($localizedDefault)) {
                continue;
            }

            if (! $this->defaultValueIsValid(
                type: $data->type,
                validationRules: $targetValidationRules,
                jsonPropertySchema: $targetJsonPropertySchema,
                referencedModelType: $targetReferencedModelType,
                value: $localizedDefault,
            )) {
                $this->throwDefaultValidation(
                    "translations.{$locale}.defaultValue",
                    'metafields::metafields/validation.custom.defaultValue.invalid_type',
                );
            }
        }
    }

    private function hasActiveValues(MetafieldDefinition $definition): bool
    {
        return Metafield::query()
            ->where('definition_id', $definition->id)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    private function blockedShapeChanges(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): array {
        $changes = [];

        if ($definition->type !== $data->type) {
            $changes['type'] = 'type';
        }

        if ($definition->is_translatable !== $this->targetIsTranslatable($definition, $data)) {
            $changes['isTranslatable'] = 'translatable flag';
        }

        if ($definition->referenced_model_type !== $this->targetReferencedModelType($definition, $data)) {
            $changes['referencedModelType'] = 'referenced model type';
        }

        if ($this->normalizeArray($definition->validation_rules) !== $this->targetValidationRules($definition, $data)) {
            $changes['validationRules'] = 'validation rules';
        }

        if ($this->normalizeArray($definition->json_property_schema) !== $this->targetJsonPropertySchema($definition, $data)) {
            $changes['jsonPropertySchema'] = 'JSON property schema';
        }

        return $changes;
    }

    private function targetIsTranslatable(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): bool {
        return $data->isTranslatable instanceof Optional
            ? $definition->is_translatable
            : $data->isTranslatable;
    }

    private function targetReferencedModelType(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): ?string {
        if (! in_array($data->type, [
            MetafieldTypeEnum::Reference,
            MetafieldTypeEnum::ReferenceList,
        ], true)) {
            return null;
        }

        return $data->referencedModelType instanceof Optional
            ? $definition->referenced_model_type
            : $data->referencedModelType;
    }

    /**
     * @return list<mixed>|null
     */
    private function targetValidationRules(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): ?array {
        if ($data->type === MetafieldTypeEnum::Json) {
            return null;
        }

        if ($data->validationRules instanceof Optional) {
            return $this->normalizeArray($definition->validation_rules);
        }

        return $this->normalizeArray($data->validationRules);
    }

    /**
     * @return list<mixed>|null
     */
    private function targetJsonPropertySchema(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): ?array {
        if ($data->type !== MetafieldTypeEnum::Json) {
            return null;
        }

        if ($data->jsonPropertySchema instanceof Optional) {
            return $this->normalizeArray($definition->json_property_schema);
        }

        return $this->normalizeArray($data->jsonPropertySchema?->toArray());
    }

    /**
     * Return the target JSON property schema as a validator-safe list.
     *
     * @return list<array<string, mixed>>|null
     */
    private function targetJsonPropertySchemaForValidation(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
    ): ?array {
        $schema = $this->targetJsonPropertySchema($definition, $data);

        if ($schema === null) {
            return null;
        }

        $normalized = [];

        foreach ($schema as $property) {
            if (! is_array($property)) {
                continue;
            }

            $normalizedProperty = [];

            foreach ($property as $key => $value) {
                if (is_string($key)) {
                    $normalizedProperty[$key] = $value;
                }
            }

            $normalized[] = $normalizedProperty;
        }

        return $normalized;
    }

    /**
     * Determine whether a default matches the target type and configured rules.
     *
     * @param  list<mixed>|null  $validationRules
     * @param  list<array<string, mixed>>|null  $jsonPropertySchema
     */
    private function defaultValueIsValid(
        MetafieldTypeEnum $type,
        ?array $validationRules,
        ?array $jsonPropertySchema,
        ?string $referencedModelType,
        mixed $value,
    ): bool {
        if ($type === MetafieldTypeEnum::Json && $jsonPropertySchema !== null) {
            try {
                $value = $type->cast($value);
            } catch (InvalidArgumentException|JsonException) {
                return false;
            }

            return MetafieldJsonPropertySchemaValidator::passes(
                $jsonPropertySchema,
                $value,
                false,
            );
        }

        if (! MetafieldValidationRuleCompiler::passes(
            $type,
            false,
            $validationRules,
            $value,
        )) {
            return false;
        }

        if ($type === MetafieldTypeEnum::Reference) {
            return MetafieldReferenceModelRegistry::referencedRecordExists(
                $referencedModelType,
                $value,
            );
        }

        if ($type !== MetafieldTypeEnum::ReferenceList) {
            return true;
        }

        try {
            $references = $type->cast($value);
        } catch (JsonException) {
            return false;
        }

        return is_array($references) && collect($references)->doesntContain(
            static fn (mixed $reference): bool => ! MetafieldReferenceModelRegistry::referencedRecordExists(
                $referencedModelType,
                $reference,
            ),
        );
    }

    private function hasDefaultValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function throwDefaultValidation(string $field, string $translationKey): never
    {
        throw ValidationException::withMessages([
            $field => [(string) trans($translationKey)],
        ]);
    }

    /**
     * @param  array<mixed>|null  $value
     * @return list<mixed>|null
     */
    private function normalizeArray(?array $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }

        return array_values($value);
    }
}
