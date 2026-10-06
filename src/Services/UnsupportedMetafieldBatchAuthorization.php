<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Data\MetafieldReferenceFacts;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Owners\OwnerBatch;

/** Refuses to infer SQL visibility from an imperative single-owner Gate callback. */
final readonly class UnsupportedMetafieldBatchAuthorization implements MetafieldBatchAuthorization
{
    /** Fail with the binding the consumer must provide. */
    private function unsupported(): never
    {
        throw new MetafieldBatchReadException('Bind MetafieldBatchAuthorization to a query-free SQL adapter before reading owner batches or using whereNvlMetafield; single-owner Gate callbacks cannot authorize these queries.');
    }

    public function authorizeOwners(OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /** @param Builder<MetafieldDefinitionAssignment> $query */
    public function scopeAssignments(Builder $query, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    public function allowsDefinition(Model $owner, MetafieldDefinition $definition): bool
    {
        $this->unsupported();
    }

    /** @param Builder<Metafield> $query */
    public function scopeValues(Builder $query, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /** @param Builder<Model> $query */
    public function scopeReferenceTargets(Builder $query, string $registeredReference, OwnerBatch $owners): void
    {
        $this->unsupported();
    }

    /** @param list<MetafieldDefinition> $definitions @param list<Metafield> $values */
    public function authorizeReferences(OwnerBatch $owners, array $definitions, array $values, MetafieldReferenceFacts $facts): void
    {
        $this->unsupported();
    }

    /** @param Builder<Metafield> $query */
    public function scopeHostValues(Builder $query, Model $ownerPrototype, string $handle): void
    {
        $this->unsupported();
    }
}
