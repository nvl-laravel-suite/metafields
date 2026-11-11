<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Resolves reference records through their registered canonical owner boundary.
 *
 * @internal
 */
final readonly class MetafieldReferenceRecordResolver
{
    /** Create the canonical reference resolver. */
    public function __construct(
        private OwnershipBoundary $boundary,
    ) {}

    /** Resolve an exact reference alias and identifier or fail closed. */
    public function resolve(mixed $alias, mixed $identifier, bool $lock = false): ?Model
    {
        $model = $this->prototype($alias);
        $id = $model instanceof Model ? $this->identifier($model, $identifier) : null;
        if (! $model instanceof Model || $id === null) {
            return null;
        }
        $query = $this->admitted($model)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $reference = $query->first();

        return $reference instanceof Model ? $this->canonical($reference, $id) : null;
    }

    /**
     * Resolve every distinct identifier in one admitted canonical query, keyed by the supplied identifier.
     *
     * Unknown, malformed and foreign-owner identifiers are absent from the result.
     *
     * @param  array<array-key, mixed>  $identifiers
     * @return array<string, Model>
     */
    public function resolveMany(mixed $alias, array $identifiers): array
    {
        $model = $this->prototype($alias);
        $ids = [];
        foreach ($identifiers as $identifier) {
            $id = $model instanceof Model ? $this->identifier($model, $identifier) : null;
            if ($id !== null && (is_string($identifier) || is_int($identifier))) {
                $ids[(string) $identifier] = $id;
            }
        }
        if (! $model instanceof Model || $ids === []) {
            return [];
        }
        $exact = [];
        $folded = [];
        foreach ($this->admitted($model)->whereKey(array_values(array_unique($ids)))->get() as $reference) {
            $key = $reference->getKey();
            if (is_int($key) || is_string($key)) {
                $exact[(string) $key] = $reference;
                $folded[strtolower((string) $key)] = $reference;
            }
        }
        $references = [];
        foreach ($ids as $identifier => $id) {
            // Without ownership the database decides key equality, as it does for a single reference.
            $reference = $exact[$id] ?? (MetafieldOwnership::declared() ? null : ($folded[strtolower($id)] ?? null));
            if ($reference instanceof Model && $this->canonical($reference, $id) instanceof Model) {
                $references[(string) $identifier] = $reference;
            }
        }

        return $references;
    }

    /** Create a blank instance of the model registered for an exact alias. */
    private function prototype(mixed $alias): ?Model
    {
        $class = is_string($alias) ? (MetafieldReferenceModelRegistry::all()[$alias] ?? null) : null;

        return is_string($class) ? new $class : null;
    }

    /** Normalize an identifier the target key can hold, without sending malformed values to its typed column. */
    private function identifier(Model $model, mixed $identifier): ?string
    {
        $id = is_string($identifier) || is_int($identifier) ? (MetafieldOwnership::declared() ? (string) $identifier : trim((string) $identifier)) : '';
        $traits = class_uses_recursive($model);
        if ($id === ''
            || (in_array(HasUuids::class, $traits, true) && ! Str::isUuid($id))
            || (in_array(HasUlids::class, $traits, true) && ! Str::isUlid($id))
            || ($model->getKeyType() === 'int' && (string) (int) $id !== $id)) {
            return null;
        }

        return $id;
    }

    /**
     * Start a target query inside the active owner boundary.
     *
     * @return Builder<Model>
     */
    private function admitted(Model $model): Builder
    {
        $query = $model->newQuery();
        if (MetafieldOwnership::active()) {
            $this->boundary->query($query, MetafieldOwnership::resource($model));
        }

        return $query;
    }

    /** Require the canonical package connection and, under ownership, the exact requested key. */
    private function canonical(Model $reference, string $id): ?Model
    {
        if ($reference->getConnection() !== (new Metafield)->getConnection()) {
            throw new OwnershipViolation('Metafield references must use the canonical package connection.');
        }
        if (MetafieldOwnership::declared()) {
            $key = $reference->getKey();
            if ((! is_int($key) && ! is_string($key)) || (string) $key !== $id) {
                return null;
            }
        }

        return $reference;
    }

    /** Determine whether the reference resolves inside the active owner boundary. */
    public function exists(mixed $alias, mixed $identifier): bool
    {
        return $this->resolve($alias, $identifier) instanceof Model;
    }
}
