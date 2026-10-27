<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Enums\SystemActor;

/**
 * Gate-backed default authorization that denies undefined definition access.
 *
 * @internal
 */
final class ConfiguredMetafieldAuthorization implements MetafieldAuthorization
{
    public function authorizeDefinition(
        Authenticatable|SystemActor $actor,
        MetafieldAbility $ability,
        ?MetafieldDefinition $definition = null,
    ): void {
        $configuredAbility = config('nvl-metafields.authorization.definition_ability');

        if ($configuredAbility === null || $configuredAbility === '') {
            $nativeAbility = match ($ability) {
                MetafieldAbility::CreateDefinition => 'metafields.create',
                MetafieldAbility::UpdateDefinition => 'metafields.update',
                MetafieldAbility::DeleteDefinition => 'metafields.delete',
                MetafieldAbility::ListDefinitions, MetafieldAbility::ViewDefinition => 'metafields.read',
                default => throw new AuthorizationException('A definition capability is required.'),
            };
            Gate::forUser($actor)->authorize($nativeAbility, $definition === null ? [] : [$definition]);

            return;
        }

        if (! is_string($configuredAbility)) {
            throw new AuthorizationException(
                'The Metafield definition Gate ability must be a string.',
            );
        }

        $arguments = [$ability->value];

        if ($definition instanceof MetafieldDefinition) {
            $arguments[] = $definition;
        }

        Gate::forUser($actor)->authorize($configuredAbility, $arguments);
    }

    public function authorizeOwner(
        Authenticatable|SystemActor $actor,
        MetafieldAbility $ability,
        ?Model $owner = null,
        ?MetafieldDefinition $definition = null,
    ): void {
        $configuredAbility = config('nvl-metafields.authorization.owner_ability');

        if (is_string($configuredAbility) && $configuredAbility !== '') {
            $arguments = [$ability->value];

            if ($owner instanceof Model) {
                $arguments[] = $owner;
            }
            if ($definition instanceof MetafieldDefinition) {
                $arguments[] = $definition;
            }

            Gate::forUser($actor)->authorize($configuredAbility, $arguments);

            return;
        }

        if (! $owner instanceof Model) {
            throw new AuthorizationException(
                'Listing Metafield owners requires an authorization binding or configured Gate ability.',
            );
        }

        Gate::forUser($actor)->authorize('update', $owner);
    }
}
