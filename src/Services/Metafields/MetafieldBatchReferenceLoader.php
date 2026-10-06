<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Data\MetafieldReferenceFacts;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Canonical bounded reference facts retain host scopes and target SQL visibility. */
final readonly class MetafieldBatchReferenceLoader
{
    /** Retain scoped boundary and query-free policy dependencies. */
    public function __construct(
        private MetafieldBatchAuthorization $policy,
        private Repository $configuration,
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
    ) {}

    /**
     * @param  list<MetafieldDefinition>  $definitions
     * @param  list<Metafield>  $values
     */
    public function load(OwnerBatch $owners, array $definitions, array $values): MetafieldReferenceFacts
    {
        $requested = [];
        $byDefinition = [];
        foreach ($definitions as $definition) {
            $byDefinition[$definition->id] = $definition;
            $this->request($requested, $definition, $definition->type === MetafieldTypeEnum::Reference
                ? $definition->default_referenced_id : $definition->default_value);
        }
        foreach ($values as $value) {
            $definition = $byDefinition[$value->definition_id] ?? null;
            if ($definition instanceof MetafieldDefinition) {
                $this->request($requested, $definition, $definition->type === MetafieldTypeEnum::Reference
                    ? $value->referenced_id : $value->value);
            }
        }
        $registry = MetafieldReferenceModelRegistry::all();
        $targets = [];
        foreach ($requested as $alias => $ids) {
            $class = $registry[$alias] ?? throw new MetafieldBatchReadException('A batch reference target is not registered.');
            $prototype = new $class;
            if ($prototype->getConnection() !== (new Metafield)->getConnection()) {
                throw new TenantBoundaryViolation('Metafield reference targets must use the canonical package connection.');
            }
            $query = $prototype->newQuery()->withoutEagerLoads();
            MetafieldOwnerPredicate::identifiers($query, $prototype->getQualifiedKeyName(), array_map(strval(...), array_keys($ids)));
            if ($this->configuration->get('nvl-tenancy.enabled') === true) {
                $this->boundary->query($query, $this->resources->forModel($prototype)->key);
            }
            $query->where(fn (Builder $nested) => $this->policy->scopeReferenceTargets($nested, $alias, $owners));
            foreach ($query->get() as $target) {
                $key = $target->getKey();
                if (is_string($key) || is_int($key)) {
                    $targets[$alias][(string) $key] = $target;
                }
            }
        }
        $facts = new MetafieldReferenceFacts($targets);
        $this->policy->authorizeReferences($owners, $definitions, $values, $facts);
        foreach ($requested as $alias => $ids) {
            foreach (array_keys($ids) as $id) {
                if (! $facts->find($alias, (string) $id) instanceof Model) {
                    throw new MetafieldBatchReadException('A stored or default Metafield reference is unavailable or denied; no reference identifiers were returned.');
                }
            }
        }

        return $facts;
    }

    /**
     * @param  array<string, array<array-key, true>>  $requested
     */
    private function request(array &$requested, MetafieldDefinition $definition, mixed $value): void
    {
        if (! in_array($definition->type, [MetafieldTypeEnum::Reference, MetafieldTypeEnum::ReferenceList], true) || $value === null || $value === '') {
            return;
        }
        $alias = $definition->referenced_model_type;
        if (! is_string($alias) || $alias === '') {
            throw new MetafieldBatchReadException('Reference fields require a registered reference target.');
        }
        $ids = $definition->type === MetafieldTypeEnum::Reference ? [$value] : $definition->type->cast($value);
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new MetafieldBatchReadException('Reference lists must contain a finite list of scalar identifiers.');
        }
        foreach ($ids as $id) {
            if ((! is_string($id) && ! is_int($id)) || (string) $id === '') {
                throw new MetafieldBatchReadException('Reference identifiers must be non-empty strings or integers.');
            }
            $requested[$alias][(string) $id] = true;
        }
        if (array_sum(array_map(count(...), $requested)) > 1000) {
            throw new MetafieldBatchReadException('Metafield batches allow at most 1,000 distinct stored and default references.');
        }
    }
}
