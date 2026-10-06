<?php

declare(strict_types=1);

namespace Nvl\Metafields\Traits;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Relations\StringMorphMany;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldQueryAdapter;

/**
 * HasMetafields
 *
 * Provides the owner-to-metafields morph relation only. Metafield reads and
 * writes must go through injected actions and services.
 */
trait HasMetafields
{
    /**
     * Compare stored scalar values; missing/default-only fields never match.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereNvlMetafield(Builder $query, string $handle, string|int|float|bool|null $value, MetafieldBatchAuthorization $policy, string $operator = '='): Builder
    {
        return Container::getInstance()->make(OwnerMetafieldQueryAdapter::class)->apply($query, $handle, $value, $policy, $operator);
    }

    /**
     * @return MorphMany<Metafield, $this>
     */
    public function metafields(): MorphMany
    {
        $related = new Metafield;

        return new StringMorphMany(
            $related->newQuery(),
            $this,
            $related->qualifyColumn('metafieldable_type'),
            $related->qualifyColumn('metafieldable_id'),
            $this->getKeyName(),
        );
    }
}
