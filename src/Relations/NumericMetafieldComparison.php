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

    /** Render a numeric expression that skips stored text no numeric cast accepts, without interpolating consumer values. */
    public function getValue(Grammar $grammar): string
    {
        $isNumeric = match ($this->driver) {
            'pgsql' => $this->column." ~ '^[[:space:]]*[+-]?([0-9]+[.]?[0-9]*|[.][0-9]+)([eE][+-]?[0-9]+)?[[:space:]]*$'",
            'sqlite' => 'TRIM('.$this->column.") <> '' AND TRIM(".$this->column.") NOT GLOB '*[^0-9.eE+-]*'",
            default => throw new MetafieldBatchReadException('Unsupported Metafield SQL comparison driver.'),
        };

        return 'CASE WHEN '.$isNumeric.' THEN CAST('.$this->column.' AS NUMERIC) END '.$this->operator.' CAST(? AS NUMERIC)';
    }
}
