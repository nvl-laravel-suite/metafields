<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldJsonPropertySchemaValidator;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldPayloadLimits;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;
use Nvl\Support\Contracts\LocaleCatalog;

/**
 * Validates native definition input before any transaction mutates persisted schema.
 *
 * @internal
 *
 * @phpstan-type DefinitionAssignment array{ownerType:string,section:string,displayOrder?:int,isRequired?:bool,isActive?:bool,uiConfig?:array<array-key,mixed>|null}
 * @phpstan-type DefinitionTranslation array{title?:string,description?:string|null,hint?:string|null,defaultValue?:mixed,properties?:array<array-key,mixed>|null}
 * @phpstan-type DefinitionMutation array{namespace:string,key:string,type:MetafieldTypeEnum,referencedModelType?:string|null,isTranslatable?:bool,isRequired?:bool,isFilterable?:bool,validationRules?:list<string>|null,jsonPropertySchema?:list<array<string,mixed>>|null,defaultValue?:mixed,displayOrder?:int,translations?:array<string,DefinitionTranslation>,assignment?:DefinitionAssignment}
 */
final readonly class MetafieldDefinitionInput
{
    public function __construct(
        private LocaleCatalog $locales,
        private MetafieldReferenceRecordResolver $references,
        private MetafieldOwnerRegistry $owners,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return DefinitionMutation
     */
    public function validate(array $attributes, bool $creating): array
    {
        Validator::make($attributes, [
            'namespace' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'type' => ['required', Rule::enum(MetafieldTypeEnum::class)],
            'referencedModelType' => ['sometimes', 'nullable', 'string', 'max:255'],
            'isTranslatable' => ['sometimes', 'boolean'],
            'isRequired' => ['sometimes', 'boolean'],
            'isFilterable' => ['sometimes', 'boolean'],
            'validationRules' => ['sometimes', 'nullable', 'array', 'list'],
            'validationRules.*' => ['string', 'max:255'],
            'jsonPropertySchema' => ['sometimes', 'nullable', 'array', 'list', 'max:'.MetafieldConfiguration::positiveInteger('nvl-metafields.limits.maximum_schema_properties', 100)],
            'jsonPropertySchema.*' => ['array:key,type,isRequired'],
            'jsonPropertySchema.*.key' => ['required', 'distinct', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'jsonPropertySchema.*.type' => ['required', Rule::enum(MetafieldJsonPropertyTypeEnum::class)],
            'jsonPropertySchema.*.isRequired' => ['sometimes', 'boolean'],
            'defaultValue' => ['sometimes', 'nullable'],
            'displayOrder' => ['sometimes', 'integer', 'min:0'],
            'translations' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'translations.*' => ['array:title,description,hint,defaultValue,properties'],
            'translations.*.title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.hint' => ['nullable', 'string', 'max:255'],
            'translations.*.properties' => ['nullable', 'array'],
            'assignment' => [$creating ? 'required' : 'sometimes', 'array:ownerType,section,displayOrder,isRequired,isActive,uiConfig'],
            'assignment.ownerType' => [$creating ? 'required' : 'required_with:assignment', 'string', Rule::in(array_keys($this->owners->all()))],
            'assignment.section' => [$creating ? 'required' : 'required_with:assignment', 'string', 'max:255'],
            'assignment.displayOrder' => ['sometimes', 'integer', 'min:0'],
            'assignment.isRequired' => ['sometimes', 'boolean'],
            'assignment.isActive' => ['sometimes', 'boolean'],
            'assignment.uiConfig' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        /** @var array{namespace:string,key:string,type:MetafieldTypeEnum|string,referencedModelType?:string|null,isTranslatable?:bool,isRequired?:bool,isFilterable?:bool,validationRules?:list<string>|null,jsonPropertySchema?:list<array<string,mixed>>|null,defaultValue?:mixed,displayOrder?:int,translations?:array<array-key,DefinitionTranslation>,assignment?:DefinitionAssignment} $validated */
        $validated = $attributes;
        $attributes = $validated;
        foreach (['isTranslatable', 'isRequired', 'isFilterable'] as $boolean) {
            if (array_key_exists($boolean, $attributes)) {
                $attributes[$boolean] = (bool) $attributes[$boolean];
            }
        }
        if (array_key_exists('assignment', $attributes)) {
            foreach (['isRequired', 'isActive'] as $boolean) {
                if (array_key_exists($boolean, $attributes['assignment'])) {
                    $attributes['assignment'][$boolean] = (bool) $attributes['assignment'][$boolean];
                }
            }
            if (array_key_exists('displayOrder', $attributes['assignment'])) {
                $attributes['assignment']['displayOrder'] = (int) $attributes['assignment']['displayOrder'];
            }
        }
        if (array_key_exists('displayOrder', $attributes)) {
            $attributes['displayOrder'] = (int) $attributes['displayOrder'];
        }
        $allowed = ['namespace', 'key', 'type', 'referencedModelType', 'isTranslatable', 'isRequired', 'isFilterable', 'validationRules', 'jsonPropertySchema', 'defaultValue', 'displayOrder', 'translations', 'assignment'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw ValidationException::withMessages(['attributes' => ['Unknown definition attributes are not accepted.']]);
        }
        foreach (array_keys($attributes['translations'] ?? []) as $locale) {
            if (! is_string($locale) || ! $this->locales->supports($locale)) {
                throw ValidationException::withMessages(['translations' => ['A supported locale is required.']]);
            }
        }
        $type = $attributes['type'];
        if (is_string($type)) {
            $type = MetafieldTypeEnum::from($type);
        }

        $attributes['type'] = $type;
        if (($attributes['isTranslatable'] ?? false) && ! $type->supportsTranslations()) {
            throw ValidationException::withMessages(['isTranslatable' => ['This type cannot be localized.']]);
        }
        if (in_array($type, [MetafieldTypeEnum::Reference, MetafieldTypeEnum::ReferenceList], true)) {
            $reference = MetafieldReferenceModelRegistry::normalizeModelClass($attributes['referencedModelType'] ?? null);
            if (! MetafieldReferenceModelRegistry::isAllowedModelClass($reference)) {
                throw ValidationException::withMessages(['referencedModelType' => ['A registered reference model is required.']]);
            }
            $attributes['referencedModelType'] = $reference;
        }
        $rules = $attributes['validationRules'] ?? null;
        $schema = $attributes['jsonPropertySchema'] ?? null;
        if ($rules !== null && MetafieldValidationRuleCompiler::invalidCustomRules($type, $rules) !== []) {
            throw ValidationException::withMessages(['validationRules' => ['The definition contains unsupported validation rules.']]);
        }
        if ($schema !== null && $type !== MetafieldTypeEnum::Json) {
            throw ValidationException::withMessages(['jsonPropertySchema' => ['JSON property schemas require a JSON definition.']]);
        }
        if (array_key_exists('defaultValue', $attributes) && $attributes['defaultValue'] !== null) {
            $default = $attributes['defaultValue'];
            if (($attributes['isTranslatable'] ?? false) || ! MetafieldPayloadLimits::accepts($default)) {
                throw ValidationException::withMessages(['defaultValue' => ['Invalid nonlocalized default.']]);
            }
            $valid = $type === MetafieldTypeEnum::Json && is_array($schema) && $schema !== []
                ? MetafieldJsonPropertySchemaValidator::passes($schema, $default, false)
                : MetafieldValidationRuleCompiler::passes($type, false, $rules, $default);
            if (! $valid) {
                throw ValidationException::withMessages(['defaultValue' => ['The default does not match the definition.']]);
            }
            if ($type === MetafieldTypeEnum::Reference && ! $this->references->exists($attributes['referencedModelType'], $default)) {
                throw ValidationException::withMessages(['defaultValue' => ['The default reference is unavailable.']]);
            }
            if ($type === MetafieldTypeEnum::ReferenceList && is_array($default)) {
                foreach ($default as $identifier) {
                    if (! $this->references->exists($attributes['referencedModelType'], $identifier)) {
                        throw ValidationException::withMessages(['defaultValue' => ['A default reference is unavailable.']]);
                    }
                }
            }
        }
        foreach ($attributes['translations'] ?? [] as $locale => $translation) {
            if (! array_key_exists('defaultValue', $translation) || $translation['defaultValue'] === null) {
                continue;
            }
            $default = $translation['defaultValue'];
            if (! ($attributes['isTranslatable'] ?? false) || ! MetafieldPayloadLimits::accepts($default)) {
                throw ValidationException::withMessages(["translations.{$locale}.defaultValue" => ['Localized defaults require a localized definition and an accepted value.']]);
            }
            $valid = $type === MetafieldTypeEnum::Json && is_array($schema) && $schema !== []
                ? MetafieldJsonPropertySchemaValidator::passes($schema, $default, false)
                : MetafieldValidationRuleCompiler::passes($type, false, $rules, $default);
            if (! $valid) {
                throw ValidationException::withMessages(["translations.{$locale}.defaultValue" => ['The default does not match the definition.']]);
            }
        }

        if (array_key_exists('assignment', $attributes)) {
            $assignment = $attributes['assignment'];
            $owner = $this->owners->configurationForType($assignment['ownerType']);
            $types = $owner['supported_types'];
            if (! in_array($type->value, $types, true) || ! in_array($assignment['section'], $owner['sections'], true)) {
                throw ValidationException::withMessages(['assignment' => ['The registered owner does not admit this field type or section.']]);
            }
        }

        /** @var DefinitionMutation $attributes */
        return $attributes;
    }
}
