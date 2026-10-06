<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Data\MetafieldReferenceFacts;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Owners\OwnerBatch;

/** Deliberately includes OR clauses to test mandatory package predicate containment. */
final class BatchMetafieldPolicy implements MetafieldBatchAuthorization
{
    /** @var list<string> */
    public array $canonicalOwnerNames = [];

    public function authorizeOwners(OwnerBatch $owners): void
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
    public function scopeAssignments(Builder $query, OwnerBatch $owners): void
    {
        $query->whereHas('definition',
            static fn (Builder $definition) => $definition->getQuery()->where('namespace', '!=', 'hidden'))
            ->orWhere('owner_type', 'unrequested');
    }

    public function allowsDefinition(Model $owner, MetafieldDefinition $definition): bool
    {
        return $definition->namespace !== 'private';
    }

    /** @param Builder<Metafield> $query */
    public function scopeValues(Builder $query, OwnerBatch $owners): void
    {
        $query->where('value', '!=', 'hidden')->orWhereNull('value')->orWhere('metafieldable_id', '9999');
    }

    /** @param Builder<Model> $query */
    public function scopeReferenceTargets(Builder $query, string $registeredReference, OwnerBatch $owners): void
    {
        $query->getQuery()->where('name', '!=', 'private')->orWhere('name', 'allowed-reference');
    }

    /** @param list<MetafieldDefinition> $definitions @param list<Metafield> $values */
    public function authorizeReferences(OwnerBatch $owners, array $definitions, array $values, MetafieldReferenceFacts $facts): void {}

    /** @param Builder<Metafield> $query */
    public function scopeHostValues(Builder $query, Model $ownerPrototype, string $handle): void
    {
        $query->where('value', '!=', 'hidden')->orWhere('metafieldable_id', '9999');
    }
}
