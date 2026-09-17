<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

/** Resolves reference records through their registered canonical tenant boundary. */
final readonly class MetafieldReferenceRecordResolver
{
    /** Create the canonical reference resolver. */
    public function __construct(
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
    ) {}

    /** Resolve an exact reference alias and identifier or fail closed. */
    public function resolve(mixed $alias, mixed $identifier, bool $lock = false): ?Model
    {
        $models = MetafieldReferenceModelRegistry::all();
        $class = is_string($alias) ? ($models[$alias] ?? null) : null;
        $id = is_string($identifier) || is_int($identifier) ? trim((string) $identifier) : '';
        if (! is_string($class) || $id === '') {
            return null;
        }
        $model = new $class;
        $resource = $this->resources->forModel($model);
        $query = $model->newQueryWithoutScopes()->whereKey($id);
        $this->boundary->query($query, $resource->key);
        if ($lock) {
            $query->lockForUpdate();
        }
        $reference = $query->first();
        if ($reference instanceof Model && $reference->getConnection() !== (new Metafield)->getConnection()) {
            throw new TenantBoundaryViolation('Metafield references must use the canonical tenant connection.');
        }

        return $reference;
    }

    /** Determine whether the reference resolves inside the active tenant boundary. */
    public function exists(mixed $alias, mixed $identifier): bool
    {
        return $this->resolve($alias, $identifier) instanceof Model;
    }
}
