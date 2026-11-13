<?php

declare(strict_types=1);

namespace Nvl\Metafields\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Connection;
use Illuminate\Database\Grammar;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;

/** Internal exact text identity expression. */
final readonly class ExactOwnerTextColumn implements Expression
{
    /** Retain a package-owned column name to wrap with the active grammar. */
    public function __construct(private string $column, private Connection $connection) {}

    /** Render an exact identity without relying on the connection's default collation. */
    public function getValue(Grammar $grammar): string
    {
        $wrapped = $grammar->wrap($this->column);

        return match ($this->connection->getDriverName()) {
            'sqlite' => 'CAST('.$wrapped.' AS TEXT) COLLATE BINARY',
            'pgsql' => 'CAST('.$wrapped.' AS TEXT) COLLATE "C"',
            default => throw new MetafieldBatchReadException('Metafield batch identity comparisons require SQLite or PostgreSQL.'),
        };
    }
}
