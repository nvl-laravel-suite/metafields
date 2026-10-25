<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;

/** Stateless SQL visibility that varies with the passed actor. */
final class ActorAwareBatchMetafieldPolicy extends BatchMetafieldPolicy
{
    /** @var list<int> */
    public array $seenActors = [];

    public function authorizeOwners(Authenticatable|SystemActor $actor, OwnerBatch $owners): void
    {
        $this->seenActors[] = $this->actorId($actor);
        parent::authorizeOwners($actor, $owners);
    }

    public function scopeAssignments(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $id = $this->actorId($actor);
        $query->whereHas('definition', static fn (Builder $definitions) => $definitions->where('namespace', 'actor_'.$id));
    }

    public function allowsDefinition(Authenticatable|SystemActor $actor, Model $owner, MetafieldDefinition $definition): bool
    {
        return $definition->namespace === 'actor_'.$this->actorId($actor);
    }

    public function scopeValues(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $query->where('value', 'value-'.$this->actorId($actor));
    }

    public function scopeHostValues(Authenticatable|SystemActor $actor, Builder $query, Model $ownerPrototype, string $handle): void
    {
        $query->where('value', 'value-'.$this->actorId($actor));
    }

    private function actorId(Authenticatable|SystemActor $actor): int
    {
        if (! $actor instanceof TestMetafieldActor || ! is_int($actor->getKey())) {
            throw new \UnexpectedValueException('The policy requires a canonical test actor.');
        }

        return $actor->getKey();
    }
}
