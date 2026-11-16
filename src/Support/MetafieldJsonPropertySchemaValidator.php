<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Support\Facades\Validator;
use JsonException;
use Nvl\Metafields\Enums\MetafieldJsonPropertyTypeEnum;

final class MetafieldJsonPropertySchemaValidator
{
    /**
     * @param  list<array<string, mixed>>|null  $schema
     */
    public static function passes(?array $schema, mixed $value, bool $required): bool
    {
        if (! is_array($schema) || $schema === []) {
            return false;
        }

        if (count($schema) > MetafieldConfiguration::positiveInteger(
            'nvl-metafields.limits.maximum_schema_properties',
            100,
        )) {
            return false;
        }

        try {
            $normalized = self::normalizeValue($value);
        } catch (JsonException) {
            return false;
        }

        if ($normalized !== null && (! is_array($normalized) || ! MetafieldPayloadLimits::accepts($normalized))) {
            return false;
        }

        if (is_array($normalized) && ! self::finiteFloatProperties($schema, $normalized)) {
            return false;
        }

        return Validator::make(
            ['value' => $normalized],
            self::rules($schema, $required),
        )->passes();
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : false;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @param  array<mixed>  $normalized
     */
    private static function finiteFloatProperties(array $schema, array $normalized): bool
    {
        foreach ($schema as $property) {
            $key = is_string(data_get($property, 'key'))
                ? trim((string) data_get($property, 'key'))
                : null;
            $typeInput = data_get($property, 'type');
            $type = $typeInput instanceof MetafieldJsonPropertyTypeEnum
                ? $typeInput
                : (is_string($typeInput) ? MetafieldJsonPropertyTypeEnum::tryFrom($typeInput) : null);

            if ($key === null || $key === '' || $type !== MetafieldJsonPropertyTypeEnum::Float || ! array_key_exists($key, $normalized)) {
                continue;
            }

            $candidate = $normalized[$key];
            if (is_numeric($candidate) && ! is_finite((float) $candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $schema
     * @return array<string, list<string>>
     */
    public static function rules(array $schema, bool $required): array
    {
        $allowedKeys = array_values(array_filter(array_map(
            static fn (array $property): ?string => is_string(data_get($property, 'key'))
                ? trim((string) data_get($property, 'key'))
                : null,
            $schema,
        )));

        $rules = [
            'value' => [
                $required ? 'required' : 'nullable',
                $allowedKeys === []
                    ? 'array'
                    : 'array:'.implode(',', $allowedKeys),
            ],
        ];

        foreach ($schema as $property) {
            $key = is_string(data_get($property, 'key'))
                ? trim((string) data_get($property, 'key'))
                : null;
            $typeInput = data_get($property, 'type');
            $type = $typeInput instanceof MetafieldJsonPropertyTypeEnum
                ? $typeInput
                : (is_string($typeInput)
                    ? MetafieldJsonPropertyTypeEnum::tryFrom($typeInput)
                    : null);

            if ($key === null || $key === '' || ! $type instanceof MetafieldJsonPropertyTypeEnum) {
                continue;
            }

            $rules["value.{$key}"] = array_merge(
                [in_array(data_get($property, 'isRequired'), [true, 1, '1'], true) ? 'required' : 'nullable'],
                $type->getValidationRules(),
            );
        }

        return $rules;
    }
}
