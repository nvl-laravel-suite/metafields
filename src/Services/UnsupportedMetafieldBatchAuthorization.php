<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Owners\OwnerBatch;

/**
 * Refuses to infer SQL visibility from an imperative single-owner Gate callback.
 *
 * @internal
 */
final readonly class UnsupportedMetafieldBatchAuthorization implements MetafieldBatchAuthorization
{
    /** Fail with the binding the consumer must provide. */
    private function unsupported(): never
    {
        throw new MetafieldBatchReadException('Bind MetafieldBatchAuthorization to a query-free SQL adapter before reading owner batches or using whereNvlMetafield; single-owner Gate callbacks cannot authorize these queries.');
    }

    public function authorizeOwners(Authenticatable|SystemActor $actor, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /** @param Builder<MetafieldDefinitionAssignment> $query */
    public function scopeAssignments(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    public function allowsDefinition(Authenticatable|SystemActor $actor, Model $owner, MetafieldDefinition $definition): bool
    {
        $this->unsupported();
    }

    /** @param Builder<Metafield> $query */
    public function scopeValues(Authenticatable|SystemActor $actor, Builder $query, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /** @param Builder<Model> $query */
    public function scopeReferenceTargets(Authenticatable|SystemActor $actor, Builder $query, string $registeredReference, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /**
     * @param  list<MetafieldDefinition>  $definitions  @param list<Metafield> $values
     * @param  array<string,array<array-key,Model>>  $facts
     */
    public function authorizeReferences(Authenticatable|SystemActor $actor, OwnerBatch $owners, array $definitions, array $values, array $facts): void
    {
        $this->unsupported();
    }

    /** @param Builder<Metafield> $query */
    public function scopeHostValues(Authenticatable|SystemActor $actor, Builder $query, Model $ownerPrototype, string $handle): void
    {
        $this->unsupported();
    }
}
