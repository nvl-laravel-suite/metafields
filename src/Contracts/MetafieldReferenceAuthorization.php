<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Consumer-owned authorization boundary for referenced records used by metafield values.
 */
interface MetafieldReferenceAuthorization
{
    /**
     * Authorize using a referenced record in a metafield value.
     *
     * @param  Model  $owner  Owner receiving the metafield value
     * @param  MetafieldDefinition  $definition  Reference definition being mutated
     * @param  Model  $reference  Referenced record selected by the mutation
     */
    public function authorize(
        Model $owner,
        MetafieldDefinition $definition,
        Model $reference,
    ): void;
}
