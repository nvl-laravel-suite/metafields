<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Relations\ExactOwnerTextColumn;
use Nvl\Metafields\Relations\TextColumnComparison;

/** Exact text identity comparisons across integer, UUID and case-insensitive SQL storage. */
final class MetafieldOwnerPredicate
{
    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function equal(Builder $query, string $column, string $value): void
    {
        $query->whereRaw(new TextColumnComparison(self::text($query, $column), self::parameter($query)), [$value]);
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function columns(Builder $query, string $left, string $right): void
    {
        $query->whereRaw(new TextColumnComparison(self::text($query, $left), self::text($query, $right)));
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  list<string>  $ids
     */
    public static function identifiers(Builder $query, string $column, array $ids): void
    {
        $query->whereIn(new ExactOwnerTextColumn($column, $query->getModel()->getConnection()->getDriverName()), $ids);
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private static function text(Builder $query, string $column): string
    {
        return (new ExactOwnerTextColumn($column, $query->getModel()->getConnection()->getDriverName()))->getValue($query->getQuery()->getGrammar());
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private static function parameter(Builder $query): string
    {
        return in_array($query->getModel()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CAST(? AS BINARY)'
            : '?';
    }
}
