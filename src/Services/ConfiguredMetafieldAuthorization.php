<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Gate-backed default authorization that denies undefined definition access.
 */
final class ConfiguredMetafieldAuthorization implements MetafieldAuthorization
{
    public function authorizeDefinition(
        MetafieldAbility $ability,
        ?MetafieldDefinition $definition = null,
    ): void {
        $configuredAbility = config('metafields.authorization.definition_ability');

        if (! is_string($configuredAbility) || $configuredAbility === '') {
            throw new AuthorizationException(
                'Metafield definition management requires an authorization binding or configured Gate ability.',
            );
        }

        $arguments = [$ability->value];

        if ($definition instanceof MetafieldDefinition) {
            $arguments[] = $definition;
        }

        Gate::authorize($configuredAbility, $arguments);
    }

    public function authorizeOwner(
        MetafieldAbility $ability,
        ?Model $owner = null,
        ?MetafieldDefinition $definition = null,
    ): void {
        $configuredAbility = config('metafields.authorization.owner_ability');

        if (is_string($configuredAbility) && $configuredAbility !== '') {
            $arguments = [$ability->value];

            if ($owner instanceof Model) {
                $arguments[] = $owner;
            }
            if ($definition instanceof MetafieldDefinition) {
                $arguments[] = $definition;
            }

            Gate::authorize($configuredAbility, $arguments);

            return;
        }

        if (! $owner instanceof Model) {
            throw new AuthorizationException(
                'Listing Metafield owners requires an authorization binding or configured Gate ability.',
            );
        }

        Gate::authorize('update', $owner);
    }
}
