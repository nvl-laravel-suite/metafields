<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Support\Facades\Validator;
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
            'metafields.limits.maximum_schema_properties',
            100,
        )
            || ! MetafieldPayloadLimits::accepts($value)) {
            return false;
        }

        return Validator::make(
            ['value' => $value],
            self::rules($schema, $required),
        )->passes();
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
                [data_get($property, 'isRequired') === true ? 'required' : 'nullable'],
                $type->getValidationRules(),
            );
        }

        return $rules;
    }
}
