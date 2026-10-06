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

    /** Render a numeric expression without interpolating consumer values. */
    public function getValue(Grammar $grammar): string
    {
        $type = match ($this->driver) {
            'sqlite', 'pgsql' => 'NUMERIC',
            'mysql', 'mariadb' => 'DECIMAL(65, 30)',
            default => throw new MetafieldBatchReadException('Unsupported Metafield SQL comparison driver.'),
        };

        return 'CAST('.$this->column.' AS '.$type.') '.$this->operator.' CAST(? AS '.$type.')';
    }
}
