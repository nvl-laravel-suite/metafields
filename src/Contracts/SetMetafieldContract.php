<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\Metafield;

/**
 * Sets one owner metafield through the canonical revision-aware sync pipeline.
 */
interface SetMetafieldContract
{
    /**
     * Set one owner metafield by its active definition handle.
     */
    public function execute(
        Model $owner,
        string $handle,
        mixed $value,
        ?string $locale = null,
        ?int $expectedRevision = null,
    ): Metafield;
}
