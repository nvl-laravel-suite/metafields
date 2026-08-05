<?php

declare(strict_types=1);

namespace Nvl\Metafields\Enums;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * MetafieldTypeEnum
 *
 * Defines supported data types for metafields with validation and casting logic.
 */
enum MetafieldTypeEnum: string
{
    case String = 'string';
    case Text = 'text';
    case RichText = 'rich_text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Float = 'float';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Json = 'json';
    case ArrayValue = 'array';
    case Enum = 'enum';
    case Reference = 'reference';
    case ReferenceList = 'reference_list';
    case Url = 'url';
    case Color = 'color';

    /**
     * Get validation rules for the type
     *
     * @return array<string>
     */
    public function getValidationRules(): array
    {
        return match ($this) {
            self::String => ['string', 'max:255'],
            self::Text, self::RichText => ['string'],
            self::Integer => ['integer'],
            self::Decimal => ['regex:/^-?\d+(?:\.\d+)?$/'],
            self::Float => ['numeric'],
            self::Boolean => ['boolean'],
            self::Date => ['date_format:Y-m-d'],
            self::DateTime => ['date_format:Y-m-d H:i:s'],
            self::Json => ['json'],
            self::ArrayValue => ['array'],
            self::Enum => ['string', 'max:255'],
            self::Reference => ['string', 'max:255'],
            self::ReferenceList => ['array', 'list', 'max:100'],
            self::Url => ['string', 'url:http,https', 'max:2048'],
            self::Color => ['string', 'hex_color'],
        };
    }

    /**
     * Cast a raw database value to the appropriate PHP type.
     */
    public function cast(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($this) {
            self::String, self::Text, self::RichText, self::Url, self::Color => $this->castString($value),

            self::Integer => is_numeric($value)
                ? (int) $value
                : throw new InvalidArgumentException('Cannot cast the supplied value to integer.'),

            self::Decimal => $this->castDecimal($value),

            self::Float => is_numeric($value)
                ? (float) $value
                : throw new InvalidArgumentException('Cannot cast the supplied value to float.'),

            self::Boolean => $this->castBoolean($value),

            self::Date => Carbon::parse($this->dateValue($value))->toDateString(),

            self::DateTime => Carbon::parse($this->dateValue($value)),

            self::Json, self::ArrayValue, self::ReferenceList => is_string($value)
                ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
                : $value,

            self::Enum, self::Reference => $this->castString($value),
        };
    }

    /**
     * Prepare a value for database storage.
     */
    public function storeCast(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::Json, self::ArrayValue, self::ReferenceList => is_array($value) || is_object($value)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value,

            self::Boolean => (int) $this->castBoolean($value),

            self::Date => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d')
                : $value,

            self::DateTime => $value instanceof DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,

            default => $value,
        };
    }

    /**
     * Determine whether values of this type may vary by content locale.
     */
    public function supportsTranslations(): bool
    {
        return match ($this) {
            self::String,
            self::Text,
            self::RichText,
            self::Json,
            self::ArrayValue,
            self::Url => true,
            default => false,
        };
    }

    /**
     * Normalize a decimal without converting it to an imprecise float.
     */
    private function castDecimal(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Cannot cast the supplied value to decimal.');
        }

        $decimal = trim((string) $value);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException("Cannot cast '{$decimal}' to decimal.");
        }

        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return $decimal === '-0' ? '0' : $decimal;
    }

    /**
     * Cast only values accepted by Laravel's boolean validation rule.
     *
     * @throws InvalidArgumentException
     */
    private function castBoolean(mixed $value): bool
    {
        return match (true) {
            $value === true, $value === 1, $value === '1' => true,
            $value === false, $value === 0, $value === '0' => false,
            default => throw new InvalidArgumentException('Cannot cast the supplied value to boolean.'),
        };
    }

    private function castString(mixed $value): string
    {
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            throw new InvalidArgumentException("Cannot cast the supplied value to {$this->value}.");
        }

        return (string) $value;
    }

    private function dateValue(mixed $value): DateTimeInterface|float|int|string|null
    {
        if ($value instanceof DateTimeInterface
            || is_float($value)
            || is_int($value)
            || is_string($value)
            || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException("Cannot cast the supplied value to {$this->value}.");
    }
}
