<?php

declare(strict_types=1);

namespace Nvl\Metafields\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;

/** Internal numeric comparison of a grammar-wrapped package value and bound scalar. */
final readonly class NumericMetafieldComparison implements Expression
{
    /** Retain the wrapped package column and a validated SQL operator. */
    public function __construct(private string $column, private string $operator, private string $driver)
    {
        if (! in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
            throw new MetafieldBatchReadException('Unsupported numeric Metafield comparison operator.');
        }
    }

    /**
     * Render a numeric expression that skips stored text outside one numeric grammar, without interpolating consumer values.
     *
     * Both drivers accept optional surrounding whitespace (space, tab, newline, vertical tab, form feed, carriage return),
     * an optional sign, digits with at most one decimal point, and an optional signed exponent of at most three digits,
     * within 1000 characters, so PostgreSQL never meets text beyond its NUMERIC digit and scale limits. SQLite has no
     * regular expressions, so its rules are GLOB patterns over the trimmed text.
     */
    public function getValue(Grammar $grammar): string
    {
        if ($this->driver === 'pgsql') {
            $isNumeric = 'char_length('.$this->column.') <= 1000 AND '.$this->column." ~ '^[[:space:]]*[+-]?([0-9]+[.]?[0-9]*|[.][0-9]+)([eE][+-]?[0-9]{1,3})?[[:space:]]*$'";

            return 'CASE WHEN '.$isNumeric.' THEN CAST('.$this->column.' AS NUMERIC) END '.$this->operator.' CAST(? AS NUMERIC)';
        }
        if ($this->driver !== 'sqlite') {
            throw new MetafieldBatchReadException('Unsupported Metafield SQL comparison driver.');
        }

        $trimmed = 'TRIM('.$this->column.', char(32, 9, 10, 11, 12, 13))';
        $isNumeric = 'length('.$this->column.') <= 1000 AND '.implode(' AND ', array_map(static fn (string $rule): string => $trimmed.' '.$rule, [
            "GLOB '*[0-9]*'",
            "NOT GLOB '*[^0-9.eE+-]*'",
            "NOT GLOB '*.*.*'",
            "NOT GLOB '*[eE]*[eE]*'",
            "NOT GLOB '*[eE]*.*'",
            "NOT GLOB '*[^eE][+-]*'",
            "NOT GLOB '*[eE]'",
            "NOT GLOB '*[eE][+-]'",
            "NOT GLOB '[eE]*'",
            "NOT GLOB '[+-][eE]*'",
            "NOT GLOB '.[eE]*'",
            "NOT GLOB '[+-].[eE]*'",
            "NOT GLOB '*[eE][0-9][0-9][0-9][0-9]*'",
            "NOT GLOB '*[eE][+-][0-9][0-9][0-9][0-9]*'",
        ]));

        return 'CASE WHEN '.$isNumeric.' THEN CAST('.$trimmed.' AS NUMERIC) END '.$this->operator.' CAST(? AS NUMERIC)';
    }
}
