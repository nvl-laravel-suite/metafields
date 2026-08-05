<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

/**
 * Normalizes untyped package configuration values.
 */
final class MetafieldConfiguration
{
    public static function string(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * Return a positive integer configuration value.
     *
     * @param  positive-int  $default
     * @return positive-int
     */
    public static function positiveInteger(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public static function ownerAliases(): array
    {
        $owners = config('metafields.owners', []);

        if (! is_array($owners)) {
            return [];
        }

        $aliases = [];

        foreach (array_keys($owners) as $alias) {
            if (is_string($alias) && $alias !== '') {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    private function __construct() {}
}
