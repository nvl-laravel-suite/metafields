<?php

declare(strict_types=1);

namespace Nvl\Metafields\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Nvl\Metafields\Models\Metafield;

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
        return $this->morphMany(Metafield::class, 'metafieldable');
    }
}
