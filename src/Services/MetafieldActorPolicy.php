<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Support\Enums\SystemActor;

/** Admits only an explicit actor granted the requested host capability. */
final readonly class MetafieldActorPolicy
{
    public function authorize(Authenticatable|SystemActor $actor, string $ability): Authenticatable|SystemActor
    {
        if ($actor === SystemActor::Background) {
            Gate::forUser($actor)->authorize('metafields.'.$ability);

            return $actor;
        }

        if (! $actor instanceof Model || ! $actor->exists || $actor->getKey() === null) {
            throw new AuthorizationException('A persisted Metafield actor is required.');
        }

        $prototype = new ($actor::class);
        $keyName = $prototype->getKeyName();
        $declaredConnection = $actor->getConnectionName();
        if ($actor->getTable() !== $prototype->getTable()
            || ($declaredConnection !== null && $declaredConnection !== $prototype->getConnection()->getName())
            || $actor->getConnection() !== $prototype->getConnection()
            || $actor->getKeyName() !== $keyName
            || $actor->isDirty($keyName)
            || $actor->getRawOriginal($keyName) !== $actor->getKey()) {
            throw new AuthorizationException('The Metafield actor identity is not canonical.');
        }

        $current = $prototype->newQuery()->whereKey($actor->getKey())->first();
        if (! $current instanceof Authenticatable) {
            throw new AuthorizationException('The Metafield actor is unavailable.');
        }

        Gate::forUser($current)->authorize('metafields.'.$ability);

        return $current;
    }
}
