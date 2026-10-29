<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Enums\SystemActor;

/**
 * Admits only an explicit actor granted the requested host capability.
 *
 * @internal
 */
final readonly class MetafieldActorPolicy
{
    public function __construct(private CanonicalActorResolver $actors) {}

    public function authorize(Authenticatable|SystemActor $actor, string $ability): Authenticatable|SystemActor
    {
        if ($actor === SystemActor::Background) {
            Gate::forUser($actor)->authorize('metafields.'.$ability);

            return $actor;
        }

        if (! $actor instanceof Model) {
            throw new AuthorizationException('A persisted Metafield actor is required.');
        }
        $declaredConnection = $actor->getConnectionName();
        if ($declaredConnection !== null && $declaredConnection !== (new ($actor::class))->getConnection()->getName()) {
            throw new AuthorizationException('The Metafield actor identity is not canonical.');
        }

        $current = $this->actors->resolve($actor);
        Gate::forUser($current)->authorize('metafields.'.$ability);

        return $current;
    }
}
