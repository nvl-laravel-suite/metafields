<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use Nvl\Metafields\Enums\MetafieldTypeEnum;

final class MetafieldValidationRuleCompiler
{
    /**
     * @var list<string>
     */
    private const SUPPORTED_CUSTOM_RULES = [
        'accepted',
        'alpha',
        'alpha_dash',
        'alpha_num',
        'array',
        'ascii',
        'between',
        'boolean',
        'date',
        'date_format',
        'decimal',
        'digits',
        'digits_between',
        'email',
        'ends_with',
        'filled',
        'hex_color',
        'in',
        'in_array_keys',
        'integer',
        'ip',
        'ipv4',
        'ipv6',
        'json',
        'list',
        'lowercase',
        'max',
        'min',
        'not_in',
        'nullable',
        'numeric',
        'present',
        'required',
        'required_array_keys',
        'size',
        'starts_with',
        'string',
        'ulid',
        'uppercase',
        'url',
        'uuid',
    ];

    /**
     * @param  array<int, mixed>|null  $customRules
     * @return array<int, string>
     */
    public static function invalidCustomRules(MetafieldTypeEnum $type, ?array $customRules): array
    {
        if (! is_array($customRules)) {
            return [];
        }

        $invalidRules = [];

        foreach ($customRules as $index => $customRule) {
            if (! is_string($customRule)) {
                continue;
            }

            $customRule = trim($customRule);

            if ($customRule === '') {
                continue;
            }

            try {
                self::parseCustomRule($type, $customRule);
            } catch (InvalidArgumentException) {
                $invalidRules[$index] = $customRule;
            }
        }

        return $invalidRules;
    }

    /**
     * @param  array<int, mixed>|null  $customRules
     * @return array<string, list<string>>
     */
    public static function compile(
        MetafieldTypeEnum $type,
        bool $isRequired,
        ?array $customRules,
    ): array {
        $rules = [
            'value' => self::baseRules($type, $isRequired),
        ];
        $schemaChildren = [];

        foreach (self::sanitizeCustomRules($customRules) as $customRule) {
            $parsedRule = self::parseCustomRule($type, $customRule);
            $rules[$parsedRule['attribute']] ??= [];
            $rules[$parsedRule['attribute']][] = $parsedRule['rule'];

            if ($parsedRule['path'] !== []) {
                self::collectSchemaChildren($schemaChildren, $parsedRule['path']);
            }
        }

        if ($type === MetafieldTypeEnum::Json) {
            foreach ($schemaChildren as $parentPath => $children) {
                $attribute = $parentPath === '' ? 'value' : "value.{$parentPath}";
                $rules[$attribute] ??= [];
                $rules[$attribute][] = 'array:'.implode(',', array_keys($children));
            }
        }

        return array_map(
            static fn (array $attributeRules): array => array_values(array_unique($attributeRules)),
            $rules,
        );
    }

    /**
     * @param  array<int, mixed>|null  $customRules
     */
    public static function passes(
        MetafieldTypeEnum $type,
        bool $isRequired,
        ?array $customRules,
        mixed $value,
    ): bool {
        try {
            return Validator::make(
                ['value' => self::normalizeValue($type, $value)],
                self::compile($type, $isRequired, $customRules),
            )->passes();
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    private static function baseRules(MetafieldTypeEnum $type, bool $isRequired): array
    {
        /** @var list<string> $rules */
        $rules = array_values(match ($type) {
            MetafieldTypeEnum::Json => ['array'],
            MetafieldTypeEnum::ArrayValue => ['array', 'list'],
            default => $type->getValidationRules(),
        });

        array_unshift($rules, $isRequired ? 'required' : 'nullable');

        return $rules;
    }

    /**
     * @param  array<int, mixed>|null  $customRules
     * @return list<string>
     */
    private static function sanitizeCustomRules(?array $customRules): array
    {
        if (! is_array($customRules)) {
            return [];
        }

        /** @var list<string> $sanitizedRules */
        $sanitizedRules = array_values(array_filter(
            array_map(
                static fn (mixed $customRule): ?string => is_string($customRule)
                    ? trim($customRule)
                    : null,
                $customRules,
            ),
            static fn (?string $customRule): bool => $customRule !== null && $customRule !== '',
        ));

        return $sanitizedRules;
    }

    /**
     * @return array{attribute: string, rule: string, path: list<string>}
     */
    private static function parseCustomRule(MetafieldTypeEnum $type, string $customRule): array
    {
        $ruleSegments = explode(':', $customRule, 2);
        $head = trim($ruleSegments[0]);
        $parameters = $ruleSegments[1] ?? null;

        if ($head === '') {
            throw new InvalidArgumentException('The validation rule head may not be empty.');
        }

        if ($type === MetafieldTypeEnum::Json && str_contains($head, '.')) {
            return self::parseJsonNestedRule($head, $parameters);
        }

        $ruleName = self::normalizeAndValidateRuleName($head);

        return [
            'attribute' => 'value',
            'rule' => $parameters === null ? $ruleName : "{$ruleName}:{$parameters}",
            'path' => [],
        ];
    }

    /**
     * @return array{attribute: string, rule: string, path: list<string>}
     */
    private static function parseJsonNestedRule(string $head, ?string $parameters): array
    {
        $lastDotPosition = strrpos($head, '.');

        if ($lastDotPosition === false) {
            throw new InvalidArgumentException('JSON nested rules must include a path and a rule name.');
        }

        $path = substr($head, 0, $lastDotPosition);
        $ruleName = substr($head, $lastDotPosition + 1);

        if ($path === '' || $ruleName === '') {
            throw new InvalidArgumentException('JSON nested rules must include a path and a rule name.');
        }

        $segments = explode('.', $path);

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                throw new InvalidArgumentException("Invalid JSON path segment [{$segment}].");
            }
        }

        $normalizedRuleName = self::normalizeAndValidateRuleName($ruleName);

        return [
            'attribute' => 'value.'.$path,
            'rule' => $parameters === null
                ? $normalizedRuleName
                : "{$normalizedRuleName}:{$parameters}",
            'path' => $segments,
        ];
    }

    private static function normalizeAndValidateRuleName(string $ruleName): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ruleName)) {
            throw new InvalidArgumentException("Unsupported validation rule [{$ruleName}].");
        }

        $normalizedRuleName = strtolower($ruleName);

        if (! in_array($normalizedRuleName, self::SUPPORTED_CUSTOM_RULES, true)) {
            throw new InvalidArgumentException("Unsupported validation rule [{$ruleName}].");
        }

        return $normalizedRuleName;
    }

    /**
     * @param  array<string, array<string, true>>  $schemaChildren
     * @param  list<string>  $path
     */
    private static function collectSchemaChildren(array &$schemaChildren, array $path): void
    {
        $parentSegments = [];

        foreach ($path as $segment) {
            $parentPath = implode('.', $parentSegments);
            $schemaChildren[$parentPath] ??= [];
            $schemaChildren[$parentPath][$segment] = true;
            $parentSegments[] = $segment;
        }
    }

    private static function normalizeValue(MetafieldTypeEnum $type, mixed $value): mixed
    {
        return match ($type) {
            MetafieldTypeEnum::Json => self::normalizeJsonValue($value),
            MetafieldTypeEnum::ArrayValue => self::normalizeArrayValue($value),
            MetafieldTypeEnum::Reference => self::normalizeReferenceValue($value),
            MetafieldTypeEnum::ReferenceList => self::normalizeArrayValue($value),
            default => $value,
        };
    }

    private static function normalizeReferenceValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Reference metafields require an integer or string model identifier.');
        }

        $identifier = trim((string) $value);

        if ($identifier === '') {
            throw new InvalidArgumentException('Reference metafield identifiers cannot be empty.');
        }

        return $identifier;
    }

    private static function normalizeJsonValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            /** @var mixed $decoded */
            $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('JSON metafields must be arrays, objects, or JSON strings.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON metafields must decode to arrays or objects.');
        }

        return $decoded;
    }

    private static function normalizeArrayValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Array metafields must be arrays or JSON array strings.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Array metafields must decode to an array.');
        }

        return $decoded;
    }
}
