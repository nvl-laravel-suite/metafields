<?php

declare(strict_types=1);

namespace Nvl\Metafields\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Relations\StringMorphMany;

/**
 * HasMetafields
 *
 * Provides the owner-to-metafields morph relation only. Metafield reads and
 * writes must go through injected actions and services.
 */
trait HasMetafields
{
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
