<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;

/** Deliberately includes OR clauses to test mandatory package predicate containment. */
class BatchMetafieldPolicy implements MetafieldBatchAuthorization
{
    /** @var list<string> */
    public array $canonicalOwnerNames = [];

    public function authorizeOwners(Authenticatable|SystemActor $actor, OwnerBatch $owners): void
    {
        foreach ($owners->owners() as $owner) {
            $name = $owner->getAttribute('name');
            if ($name === 'denied') {
                throw new AuthorizationException('Owner denied.');
            }
            $this->canonicalOwnerNames[] = is_string($name) ? $name : '';
        }
    }

    /** @param Builder<MetafieldDefinitionAssignment> $query */
    public function scopeAssignments(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $query->whereHas('definition',
            static fn (Builder $definition) => $definition->getQuery()->where('namespace', '!=', 'hidden'))
            ->orWhere('owner_type', 'unrequested');
    }

    public function allowsDefinition(Authenticatable|SystemActor $actor, Model $owner, MetafieldDefinition $definition): bool
    {
        return $definition->namespace !== 'private';
    }

    /** @param Builder<Metafield> $query */
    public function scopeValues(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $query->where('value', '!=', 'hidden')->orWhereNull('value')->orWhere('metafieldable_id', '9999');
    }

    /** @param Builder<Model> $query */
    public function scopeReferenceTargets(Authenticatable|SystemActor $actor, Builder $query, string $registeredReference, OwnerBatch $owners): void
    {
        $query->getQuery()->where('name', '!=', 'private')->orWhere('name', 'allowed-reference');
    }

    /**
     * @param  list<MetafieldDefinition>  $definitions  @param list<Metafield> $values
     * @param  array<string,array<array-key,Model>>  $facts
     */
    public function authorizeReferences(Authenticatable|SystemActor $actor, OwnerBatch $owners, array $definitions, array $values, array $facts): void {}

    /** @param Builder<Metafield> $query */
    public function scopeHostValues(Authenticatable|SystemActor $actor, Builder $query, Model $ownerPrototype, string $handle): void
    {
        $query->where('value', '!=', 'hidden')->orWhere('metafieldable_id', '9999');
    }
}
