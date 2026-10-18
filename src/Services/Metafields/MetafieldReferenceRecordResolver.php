<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Resolves reference records through their registered canonical tenant boundary. */
final readonly class MetafieldReferenceRecordResolver
{
    /** Create the canonical reference resolver. */
    public function __construct(
        private OwnershipBoundary $boundary,
    ) {}

    /** Resolve an exact reference alias and identifier or fail closed. */
    public function resolve(mixed $alias, mixed $identifier, bool $lock = false): ?Model
    {
        $models = MetafieldReferenceModelRegistry::all();
        $class = is_string($alias) ? ($models[$alias] ?? null) : null;
        $id = is_string($identifier) || is_int($identifier) ? (MetafieldOwnership::declared() ? (string) $identifier : trim((string) $identifier)) : '';
        if (! is_string($class) || $id === '') {
            return null;
        }
        $model = new $class;
        $traits = class_uses_recursive($model);
        if ((in_array(HasUuids::class, $traits, true) && ! Str::isUuid($id))
            || (in_array(HasUlids::class, $traits, true) && ! Str::isUlid($id))) {
            return null;
        }
        $query = $model->newQuery()->whereKey($id);
        if (MetafieldOwnership::active()) {
            $resource = MetafieldOwnership::resource($model);
            $this->boundary->query($query, $resource);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $reference = $query->first();
        if ($reference instanceof Model && $reference->getConnection() !== (new Metafield)->getConnection()) {
            throw new TenantBoundaryViolation('Metafield references must use the canonical tenant connection.');
        }

        if (MetafieldOwnership::declared() && $reference instanceof Model) {
            $key = $reference->getKey();
            if ((! is_int($key) && ! is_string($key)) || (string) $key !== $id) {
                return null;
            }
        }

        return $reference;
    }

    /** Determine whether the reference resolves inside the active tenant boundary. */
    public function exists(mixed $alias, mixed $identifier): bool
    {
        return $this->resolve($alias, $identifier) instanceof Model;
    }
}
