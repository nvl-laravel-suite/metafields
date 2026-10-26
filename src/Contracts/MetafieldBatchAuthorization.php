<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;

/**
 * Query-free SQL and loaded-fact authorization for batch reads.
 *
 * @api
 */
interface MetafieldBatchAuthorization
{
    /** Admit all canonical owners without querying. */
    public function authorizeOwners(Authenticatable|SystemActor $actor, OwnerBatch $owners): void;

    /** @param Builder<MetafieldDefinitionAssignment> $query */
    public function scopeAssignments(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void;

    /** Admit one loaded definition for a canonical owner without querying. */
    public function allowsDefinition(Authenticatable|SystemActor $actor, Model $owner, MetafieldDefinition $definition): bool;

    /** @param Builder<Metafield> $query */
    public function scopeValues(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void;

    /** @param Builder<Model> $query */
    public function scopeReferenceTargets(Authenticatable|SystemActor $actor, Builder $query, string $registeredReference, OwnerBatch $owners): void;

    /**
     * Authorize all stored and default references using only loaded facts.
     *
     * @param  list<MetafieldDefinition>  $definitions
     * @param  list<Metafield>  $values
     * @param  array<string,array<array-key,Model>>  $facts
     */
    public function authorizeReferences(Authenticatable|SystemActor $actor, OwnerBatch $owners, array $definitions, array $values, array $facts): void;

    /** @param Builder<Metafield> $query */
    public function scopeHostValues(Authenticatable|SystemActor $actor, Builder $query, Model $ownerPrototype, string $handle): void;
}
