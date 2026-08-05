<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Gate-backed reference authorization that fails closed until consumers configure an ability.
 */
final class ConfiguredMetafieldReferenceAuthorization implements MetafieldReferenceAuthorization
{
    /**
     * Authorize using a referenced record through the configured Gate ability.
     *
     * @param  Model  $owner  Owner receiving the metafield value
     * @param  MetafieldDefinition  $definition  Reference definition being mutated
     * @param  Model  $reference  Referenced record selected by the mutation
     *
     * @throws AuthorizationException
     */
    public function authorize(
        Model $owner,
        MetafieldDefinition $definition,
        Model $reference,
    ): void {
        $configuredAbility = config('metafields.authorization.reference_ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Metafield references require an authorization binding or configured Gate ability.',
            );
        }

        Gate::authorize($configuredAbility, [$owner, $definition, $reference]);
    }
}
