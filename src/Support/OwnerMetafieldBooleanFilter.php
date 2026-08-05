<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Applies owner metafield boolean constraints using Metafields storage rules.
 */
final class OwnerMetafieldBooleanFilter
{
    /**
     * Constrain an owner query by a boolean metafield definition handle.
     *
     * Missing or non-boolean definitions are treated as a no-op so consumers do
     * not need to duplicate Metafields definition lookup or storage encoding.
     *
     * @template TModel of EloquentModel
     *
     * @param  Builder<TModel>  $query  Owner query being constrained
     * @param  non-empty-string  $relation  Owner relationship name for metafields
     * @param  non-empty-string  $handle  Definition handle in namespace.key format
     * @param  bool  $expected  Expected boolean state
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $relation, string $handle, bool $expected): Builder
    {
        $definition = self::booleanDefinition($handle);

        if (! $definition instanceof MetafieldDefinition) {
            return $query;
        }

        if ($expected) {
            return $query->whereHas(
                $relation,
                static function (Builder $metafieldQuery) use ($definition): void {
                    self::whereDefinitionValue($metafieldQuery, $definition, true);
                },
            );
        }

        return $query->where(
            static function (Builder $ownerQuery) use ($definition, $relation): void {
                $ownerQuery
                    ->whereDoesntHave(
                        $relation,
                        static function (Builder $metafieldQuery) use ($definition): void {
                            $metafieldQuery->whereBelongsTo($definition, 'definition');
                        },
                    )
                    ->orWhereHas(
                        $relation,
                        static function (Builder $metafieldQuery) use ($definition): void {
                            self::whereDefinitionValue($metafieldQuery, $definition, false);
                        },
                    );
            },
        );
    }

    /**
     * Resolve a boolean definition by handle.
     */
    private static function booleanDefinition(string $handle): ?MetafieldDefinition
    {
        return MetafieldDefinition::query()
            ->active()
            ->where('active_handle', $handle)
            ->where('type', MetafieldTypeEnum::Boolean->value)
            ->where('is_filterable', true)
            ->first();
    }

    /**
     * Apply definition and stored boolean value constraints to a metafield query.
     *
     * @param  Builder<EloquentModel>  $query  Metafield relationship query
     */
    private static function whereDefinitionValue(
        Builder $query,
        MetafieldDefinition $definition,
        bool $expected,
    ): void {
        $query
            ->whereBelongsTo($definition, 'definition')
            ->whereRaw(Metafield::TABLE.'.value = ?', [self::storedBooleanValue($expected)]);
    }

    /**
     * Encode booleans using the Metafields type storage contract.
     */
    private static function storedBooleanValue(bool $value): string
    {
        return $value ? '1' : '0';
    }
}
