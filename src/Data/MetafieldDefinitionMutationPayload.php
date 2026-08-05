<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldJsonPropertySchemaValidator;
use Nvl\Metafields\Support\MetafieldPayloadLimits;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;
use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\Optional as TypeScriptOptional;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
/**
 * Shared typed definition mutation shape and cross-field validation contract.
 */
abstract class MetafieldDefinitionMutationPayload extends Data
{
    use DataTransform;

    /**
     * @param  string[]|Optional|null  $validationRules
     * @param  DataCollection<int, MetafieldJsonProperty>|Optional|null  $jsonPropertySchema
     * @param  array<array-key, mixed>|bool|float|int|string|Optional|null  $defaultValue
     * @param  array<string, array<string, mixed>>|Optional|null  $translations
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $namespace,
        #[LiteralTypeScriptType('string')]
        public readonly string $key,
        #[TypeScriptType(MetafieldTypeEnum::class)]
        public readonly MetafieldTypeEnum $type,
        #[TypeScriptType(AssignMetafieldDefinitionPayload::class)]
        public readonly AssignMetafieldDefinitionPayload $assignment,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string | null')]
        public readonly string|Optional|null $referencedModelType = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isTranslatable = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isRequired = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('boolean')]
        public readonly bool|Optional $isFilterable = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('string[] | null')]
        public readonly array|Optional|null $validationRules = new Optional,
        #[TypeScriptOptional]
        #[DataCollectionOf(MetafieldJsonProperty::class)]
        public readonly DataCollection|Optional|null $jsonPropertySchema = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('unknown | null')]
        public readonly array|bool|float|int|string|Optional|null $defaultValue = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('number')]
        public readonly int|Optional $displayOrder = new Optional,
        #[TypeScriptOptional]
        #[LiteralTypeScriptType('Record<string, { title: string; description?: string | null; hint?: string | null; defaultValue?: unknown; properties?: Record<string, unknown> | null }> | null')]
        public readonly array|Optional|null $translations = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'namespace' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'type' => ['required', Rule::enum(MetafieldTypeEnum::class)],
            'referencedModelType' => ['nullable', 'string', 'max:255'],
            'isTranslatable' => ['sometimes', 'boolean'],
            'isRequired' => ['sometimes', 'boolean'],
            'isFilterable' => ['sometimes', 'boolean'],
            'validationRules' => ['nullable', 'array', 'list'],
            'validationRules.*' => ['string', 'max:255'],
            'jsonPropertySchema' => [
                'nullable',
                'array',
                'list',
                'max:'.MetafieldConfiguration::positiveInteger(
                    'metafields.limits.maximum_schema_properties',
                    100,
                ),
            ],
            'jsonPropertySchema.*' => ['array'],
            'jsonPropertySchema.*.key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'jsonPropertySchema.*.type' => ['required', Rule::enum(MetafieldJsonPropertyTypeEnum::class)],
            'jsonPropertySchema.*.isRequired' => ['sometimes', 'boolean'],
            'defaultValue' => ['nullable'],
            'displayOrder' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['nullable', 'array', 'min:1', new SupportedLocaleMapRule],
            'translations.*' => ['array:title,description,hint,defaultValue,properties'],
            'translations.*.title' => ['sometimes', 'required', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.hint' => ['nullable', 'string', 'max:255'],
            'translations.*.defaultValue' => ['nullable'],
            'translations.*.properties' => ['nullable', 'array'],
            'assignment' => ['required', 'array'],
            'assignment.ownerType' => [
                'required',
                'string',
                Rule::in(MetafieldConfiguration::ownerAliases()),
            ],
            'assignment.section' => ['required', 'string', 'max:255'],
            'assignment.displayOrder' => ['nullable', 'integer', 'min:0'],
            'assignment.isRequired' => ['boolean'],
            'assignment.isActive' => ['boolean'],
            'assignment.uiConfig' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messages(): array
    {
        return self::translatedMessages('metafields::metafields');
    }

    /**
     * @return array<string, mixed>
     */
    public static function attributes(): array
    {
        return self::translatedAttributes('metafields::metafields');
    }

    /**
     * Attach cross-field definition, assignment, and default-value validators.
     */
    public static function withValidator(Validator $validator): void
    {
        $defaultsToNonTranslatable = static::class === CreateMetafieldDefinitionPayload::class;

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);

            if (! in_array($type, [
                MetafieldTypeEnum::Reference,
                MetafieldTypeEnum::ReferenceList,
            ], true)) {
                return;
            }

            $referencedModelType = MetafieldReferenceModelRegistry::normalizeModelClass(
                data_get($payload, 'referencedModelType'),
            );

            if ($referencedModelType === null) {
                $validator->errors()->add(
                    'referencedModelType',
                    (string) trans('metafields::metafields/validation.custom.referencedModelType.required_if'),
                );

                return;
            }

            if (MetafieldReferenceModelRegistry::isAllowedModelClass($referencedModelType)) {
                return;
            }

            $validator->errors()->add(
                'referencedModelType',
                (string) trans('metafields::metafields/validation.custom.referencedModelType.not_allowed'),
            );
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);

            if (data_get($payload, 'isTranslatable') === true
                && $type instanceof MetafieldTypeEnum
                && ! $type->supportsTranslations()) {
                $validator->errors()->add(
                    'isTranslatable',
                    (string) trans(
                        'metafields::metafields/validation.custom.isTranslatable.unsupported_type',
                    ),
                );
            }
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);
            $ownerType = data_get($payload, 'assignment.ownerType');
            $section = data_get($payload, 'assignment.section');
            $owners = config('metafields.owners', []);
            $owner = is_array($owners) && is_string($ownerType)
                ? ($owners[$ownerType] ?? null)
                : null;

            if (! $type instanceof MetafieldTypeEnum || ! is_array($owner)) {
                return;
            }

            $supportedTypes = $owner['supported_types'] ?? array_map(
                static fn (MetafieldTypeEnum $fieldType): string => $fieldType->value,
                MetafieldTypeEnum::cases(),
            );

            if (is_array($supportedTypes)
                && ! in_array($type->value, $supportedTypes, true)) {
                $validator->errors()->add(
                    'assignment.ownerType',
                    (string) trans(
                        'metafields::metafields/validation.custom.assignment.ownerType.unsupported_type',
                    ),
                );
            }

            $sections = $owner['sections'] ?? ['general'];

            if (is_string($section)
                && is_array($sections)
                && ! in_array($section, $sections, true)) {
                $validator->errors()->add(
                    'assignment.section',
                    (string) trans(
                        'metafields::metafields/validation.custom.assignment.section.unsupported',
                    ),
                );
            }
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);

            if (! $type instanceof MetafieldTypeEnum) {
                return;
            }

            $defaultValue = data_get($payload, 'defaultValue');

            if ($defaultValue === null || $defaultValue === '') {
                return;
            }

            if (data_get($payload, 'isTranslatable') === true) {
                $validator->errors()->add(
                    'defaultValue',
                    (string) trans(
                        'metafields::metafields/validation.custom.defaultValue.nonlocalized_storage',
                    ),
                );

                return;
            }

            $validationRules = data_get($payload, 'validationRules');
            $jsonPropertySchema = self::jsonPropertySchemaFromPayload($payload);

            if ($type === MetafieldTypeEnum::Reference) {
                self::validateReferenceDefaultValue(
                    $validator,
                    $payload,
                    self::listOrNull($validationRules),
                    $defaultValue,
                );

                return;
            }

            if ($type === MetafieldTypeEnum::ReferenceList) {
                if (! MetafieldValidationRuleCompiler::passes(
                    $type,
                    false,
                    self::listOrNull($validationRules),
                    $defaultValue,
                )
                    || ! is_array($defaultValue)
                    || ! array_is_list($defaultValue)
                    || collect($defaultValue)->contains(
                        static fn (mixed $reference): bool => ! MetafieldReferenceModelRegistry::referencedRecordExists(
                            data_get($payload, 'referencedModelType'),
                            $reference,
                        ),
                    )) {
                    $validator->errors()->add(
                        'defaultValue',
                        (string) trans('metafields::metafields/validation.custom.defaultValue.invalid_type'),
                    );
                }

                return;
            }

            if (self::defaultValueIsValid(
                $type,
                self::listOrNull($validationRules),
                $jsonPropertySchema,
                $defaultValue,
            )) {
                return;
            }

            $validator->errors()->add(
                'defaultValue',
                (string) trans('metafields::metafields/validation.custom.defaultValue.invalid_type'),
            );
        });

        $validator->after(static function (Validator $validator) use ($defaultsToNonTranslatable): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);
            $translations = data_get($payload, 'translations');

            if (! $type instanceof MetafieldTypeEnum || ! is_array($translations)) {
                return;
            }

            $validationRules = self::listOrNull(data_get($payload, 'validationRules'));
            $jsonPropertySchema = self::jsonPropertySchemaFromPayload($payload);
            $isTranslatable = data_get($payload, 'isTranslatable');

            foreach ($translations as $locale => $translation) {
                if (! is_string($locale)
                    || ! is_array($translation)
                    || ! array_key_exists('defaultValue', $translation)) {
                    continue;
                }

                $defaultValue = $translation['defaultValue'];

                if ($defaultValue === null || $defaultValue === '') {
                    continue;
                }

                if ($isTranslatable === false
                    || ($isTranslatable === null && $defaultsToNonTranslatable)) {
                    $validator->errors()->add(
                        "translations.{$locale}.defaultValue",
                        (string) trans(
                            'metafields::metafields/validation.custom.translations.defaultValue.localized_storage',
                        ),
                    );

                    continue;
                }

                if (! self::defaultValueIsValid(
                    $type,
                    $validationRules,
                    $jsonPropertySchema,
                    $defaultValue,
                )) {
                    $validator->errors()->add(
                        "translations.{$locale}.defaultValue",
                        (string) trans(
                            'metafields::metafields/validation.custom.defaultValue.invalid_type',
                        ),
                    );
                }
            }
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);

            if (! $type instanceof MetafieldTypeEnum) {
                return;
            }

            $validationRules = data_get($payload, 'validationRules');

            if ($type === MetafieldTypeEnum::Json) {
                return;
            }

            $invalidCustomRules = MetafieldValidationRuleCompiler::invalidCustomRules(
                $type,
                self::listOrNull($validationRules),
            );

            foreach ($invalidCustomRules as $index => $invalidCustomRule) {
                $validator->errors()->add(
                    "validationRules.{$index}",
                    (string) trans('metafields::metafields/validation.custom.validationRules.invalid_rule', [
                        'rule' => $invalidCustomRule,
                    ]),
                );
            }
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();
            $typeInput = data_get($payload, 'type');
            $type = $typeInput instanceof MetafieldTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldTypeEnum::tryFrom($typeInput) : null);
            $jsonPropertySchema = self::jsonPropertySchemaFromPayload($payload);
            $validationRules = data_get($payload, 'validationRules');

            if ($type === MetafieldTypeEnum::Json && $jsonPropertySchema === []) {
                $validator->errors()->add(
                    'jsonPropertySchema',
                    (string) trans('metafields::metafields/validation.custom.jsonPropertySchema.required_for_json'),
                );
            }

            if ($type !== MetafieldTypeEnum::Json && $jsonPropertySchema !== []) {
                $validator->errors()->add(
                    'jsonPropertySchema',
                    (string) trans('metafields::metafields/validation.custom.jsonPropertySchema.only_for_json'),
                );
            }

            if ($type === MetafieldTypeEnum::Json
                && is_array($validationRules)
                && $validationRules !== []) {
                $validator->errors()->add(
                    'validationRules',
                    (string) trans('metafields::metafields/validation.custom.validationRules.forbidden_for_json'),
                );
            }

            $keys = array_values(array_filter(array_map(
                static fn (array $property): ?string => is_string(data_get($property, 'key'))
                    ? trim((string) data_get($property, 'key'))
                    : null,
                $jsonPropertySchema,
            )));

            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add(
                    'jsonPropertySchema',
                    (string) trans('metafields::metafields/validation.custom.jsonPropertySchema.unique_keys'),
                );
            }
        });

        $validator->after(static function (Validator $validator): void {
            $payload = $validator->getData();

            foreach (['defaultValue', 'assignment.uiConfig'] as $path) {
                $value = data_get($payload, $path);

                if (is_array($value) && ! MetafieldPayloadLimits::accepts($value)) {
                    $validator->errors()->add(
                        $path,
                        (string) trans('metafields::metafields/validation.custom.structured_limit'),
                    );
                }
            }

            $translations = data_get($payload, 'translations');

            if (! is_array($translations)) {
                return;
            }

            foreach ($translations as $locale => $translation) {
                if (! is_string($locale) || ! is_array($translation)) {
                    continue;
                }

                foreach (['defaultValue', 'properties'] as $field) {
                    $value = $translation[$field] ?? null;

                    if (is_array($value) && ! MetafieldPayloadLimits::accepts($value)) {
                        $validator->errors()->add(
                            "translations.{$locale}.{$field}",
                            (string) trans('metafields::metafields/validation.custom.structured_limit'),
                        );
                    }
                }
            }
        });
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<mixed>|null  $validationRules
     */
    private static function validateReferenceDefaultValue(
        Validator $validator,
        array $payload,
        ?array $validationRules,
        mixed $defaultValue,
    ): void {
        $referencedModelType = MetafieldReferenceModelRegistry::normalizeModelClass(
            data_get($payload, 'referencedModelType'),
        );

        if (! MetafieldReferenceModelRegistry::isAllowedModelClass($referencedModelType)) {
            return;
        }

        if (! MetafieldValidationRuleCompiler::passes(
            MetafieldTypeEnum::Reference,
            false,
            $validationRules,
            $defaultValue,
        )) {
            $validator->errors()->add(
                'defaultValue',
                (string) trans('metafields::metafields/validation.custom.defaultValue.invalid_type'),
            );

            return;
        }

        if (MetafieldReferenceModelRegistry::referencedRecordExists($referencedModelType, $defaultValue)) {
            return;
        }

        $validator->errors()->add(
            'defaultValue',
            (string) trans('metafields::metafields/validation.custom.defaultValue.invalid_reference'),
        );
    }

    /**
     * @param  array<int, mixed>|null  $validationRules
     * @param  list<array<string, mixed>>  $jsonPropertySchema
     */
    private static function defaultValueIsValid(
        MetafieldTypeEnum $type,
        ?array $validationRules,
        array $jsonPropertySchema,
        mixed $defaultValue,
    ): bool {
        if ($type === MetafieldTypeEnum::Json && $jsonPropertySchema !== []) {
            return MetafieldJsonPropertySchemaValidator::passes(
                $jsonPropertySchema,
                $defaultValue,
                false,
            );
        }

        return MetafieldValidationRuleCompiler::passes($type, false, $validationRules, $defaultValue);
    }

    /**
     * @param  array<mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function jsonPropertySchemaFromPayload(array $payload): array
    {
        $schema = data_get($payload, 'jsonPropertySchema');

        if (! is_array($schema)) {
            return [];
        }

        $properties = [];

        foreach ($schema as $property) {
            if (! is_array($property)) {
                continue;
            }

            $normalized = [];

            foreach ($property as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            $properties[] = $normalized;
        }

        return $properties;
    }

    /**
     * @return list<mixed>|null
     */
    private static function listOrNull(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value)
            ? $value
            : null;
    }
}
