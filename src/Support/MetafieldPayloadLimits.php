<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use JsonException;

/**
 * Enforces bounded structured values before schema validation or persistence.
 */
final class MetafieldPayloadLimits
{
    /**
     * Determine whether a structured value stays within configured limits.
     */
    public static function accepts(mixed $value): bool
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (strlen($encoded) > MetafieldConfiguration::positiveInteger(
            'metafields.limits.maximum_json_bytes',
            262_144,
        )) {
            return false;
        }

        $items = 0;

        return self::inspect(
            $value,
            depth: 0,
            items: $items,
            maximumDepth: MetafieldConfiguration::positiveInteger(
                'metafields.limits.maximum_json_depth',
                16,
            ),
            maximumItems: MetafieldConfiguration::positiveInteger(
                'metafields.limits.maximum_json_items',
                1_000,
            ),
        );
    }

    /**
     * Walk one JSON-compatible value while bounding depth and total items.
     */
    private static function inspect(
        mixed $value,
        int $depth,
        int &$items,
        int $maximumDepth,
        int $maximumItems,
    ): bool {
        if ($depth > $maximumDepth) {
            return false;
        }

        if (! is_array($value) && ! is_object($value)) {
            return true;
        }

        $children = is_array($value) ? $value : get_object_vars($value);
        $items += count($children);

        if ($items > $maximumItems) {
            return false;
        }

        foreach ($children as $child) {
            if (! self::inspect(
                $child,
                $depth + 1,
                $items,
                $maximumDepth,
                $maximumItems,
            )) {
                return false;
            }
        }

        return true;
    }
}
