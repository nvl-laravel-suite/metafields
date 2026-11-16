<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Nvl\Metafields\Contracts\HasMetafieldScope;
use Nvl\Metafields\Contracts\HasSqlMetafieldScope;
use Nvl\Metafields\Relations\ExactOwnerTextColumn;
use Nvl\Metafields\Relations\TextColumnComparison;

/** @internal */
final class MetafieldOwnerScope
{
    /** @param class-string<Model> $model
     * @return list<string>
     */
    public static function options(string $model): array
    {
        return is_subclass_of($model, HasMetafieldScope::class)
            ? array_values(array_unique(array_column($model::metafieldScopeOptions(), 'value')))
            : [];
    }

    public static function key(Model $owner): ?string
    {
        $key = $owner instanceof HasMetafieldScope ? $owner->metafieldScopeKey() : null;

        return $key !== null && in_array($key, self::options($owner::class), true) ? $key : null;
    }

    /** @param list<string>|null $assigned */
    public static function accepts(?array $assigned, ?string $key): bool
    {
        return $assigned === null || ($key !== null && in_array($key, $assigned, true));
    }

    /**
     * Correlate an assignment's allowed JSON scope keys with the stored owner scope.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public static function constrainSql(Builder $query, Model $owner): void
    {
        $query->where(static function (Builder $allowed) use ($owner): void {
            $allowed->whereNull('owner_scopes');
            if (! $owner instanceof HasSqlMetafieldScope) {
                return;
            }
            $column = $owner::metafieldScopeColumn();
            $connection = $owner->getConnection();
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
                throw new \LogicException('Metafield scope SQL requires a plain owner column name.');
            }
            $grammar = $connection->getQueryGrammar();
            $scopeColumn = $grammar->wrap($allowed->getModel()->getTable().'.owner_scopes');
            $alias = $grammar->wrapTable('nvl_assignment_scope');
            self::assertWrappedIdentifier($scopeColumn);
            self::assertWrappedIdentifier($alias);
            $table = match ($connection->getDriverName()) {
                'pgsql' => "pg_catalog.jsonb_array_elements_text(CAST({$scopeColumn} AS jsonb)) AS {$alias}(value)",
                'sqlite' => "json_each({$scopeColumn}) AS {$alias}",
                default => null,
            };
            if ($table === null) {
                return;
            }
            $ownerScope = new ExactOwnerTextColumn($owner->qualifyColumn($column), $connection);
            $options = self::options($owner::class);
            $matches = $connection->query()->selectRaw('1')->from(new Expression($table));
            $matches->whereIn('nvl_assignment_scope.value', $options)
                ->whereRaw(new TextColumnComparison($grammar->wrap('nvl_assignment_scope.value'), $ownerScope->getValue($grammar)));
            $allowed->orWhereExists($matches);
        });
    }

    /** @phpstan-assert literal-string $identifier */
    private static function assertWrappedIdentifier(string $identifier): void
    {
        if (preg_match('/^"[A-Za-z_][A-Za-z0-9_]*"(?:\."[A-Za-z_][A-Za-z0-9_]*")*$/D', $identifier) !== 1) {
            throw new \LogicException('Metafield scope SQL requires a quoted database identifier.');
        }
    }
}
