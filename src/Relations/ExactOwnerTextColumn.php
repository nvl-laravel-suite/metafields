<?php

declare(strict_types=1);

namespace Nvl\Metafields\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;

/** Internal exact text identity expression, including binary grouping on MySQL. */
final readonly class ExactOwnerTextColumn implements Expression
{
    /** Retain a package-owned column name to wrap with the active grammar. */
    public function __construct(private string $column, private string $driver) {}

    /** Render an exact identity without relying on the connection's default collation. */
    public function getValue(Grammar $grammar): string
    {
        $wrapped = $grammar->wrap($this->column);

        return match ($this->driver) {
            'sqlite' => 'CAST('.$wrapped.' AS TEXT) COLLATE BINARY',
            'pgsql' => 'CAST('.$wrapped.' AS TEXT) COLLATE "C"',
            'mysql', 'mariadb' => 'CAST('.$wrapped.' AS BINARY)',
            default => throw new MetafieldBatchReadException('Metafield batch identity comparisons require SQLite, PostgreSQL, MySQL or MariaDB.'),
        };
    }
}
