<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Nvl\Metafields\Enums\MetafieldTypeEnum;

/**
 * Serializes resolved metafield values without exposing application model state.
 */
final class MetafieldValueSerializer
{
    /**
     * Normalize a typed metafield value for API and TypeScript consumers.
     */
    public static function serialize(MetafieldTypeEnum $type, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return match ($type) {
                MetafieldTypeEnum::Date => $value->format('Y-m-d'),
                default => $value->format(DateTimeInterface::ATOM),
            };
        }

        if ($type === MetafieldTypeEnum::ReferenceList) {
            $references = $value instanceof Enumerable
                ? $value->all()
                : (is_array($value) ? $value : []);

            return array_values(array_filter(
                array_map(self::identifier(...), $references),
                static fn (?string $identifier): bool => $identifier !== null,
            ));
        }

        if ($value instanceof Model) {
            return self::identifier($value);
        }

        return $value;
    }

    /**
     * Resolve a model or scalar identifier into its public string representation.
     */
    private static function identifier(mixed $value): ?string
    {
        $identifier = $value instanceof Model ? $value->getKey() : $value;

        return is_string($identifier) || is_int($identifier)
            ? (string) $identifier
            : null;
    }
}
