<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Resolves configured metafield owner models without leaking persistence into controllers.
 */
final class MetafieldOwnerModelResolver
{
    /**
     * Create the configured owner model resolver.
     *
     * @param  MetafieldOwnerRegistry  $ownerRegistry  Metafield owner registry
     */
    public function __construct(
        private readonly MetafieldOwnerRegistry $ownerRegistry,
        private readonly OwnershipBoundary $boundary,
    ) {}

    /**
     * Resolve one owner model by its configured type and identifier.
     *
     * @param  string  $ownerType  Configured owner type
     * @param  string  $ownerId  Owner identifier
     * @return Model Resolved owner model
     */
    public function resolve(string $ownerType, string $ownerId): Model
    {
        if (! $this->ownerRegistry->supportsRuntimeEditing($ownerType)) {
            throw new InvalidArgumentException(
                "Metafield owner type [{$ownerType}] does not support runtime editing.",
            );
        }

        $configuration = $this->ownerRegistry->configurationForType($ownerType);
        $modelClass = $configuration['model'];

        /** @var class-string<Model> $modelClass */
        $owner = $modelClass::query()->findOrFail($ownerId);

        return $this->canonical($owner);
    }

    /** Reload one programmatic owner through its registered tenant resource boundary. */
    public function canonical(Model $owner, bool $lock = false): Model
    {
        MetafieldOwnership::declared();
        if ($owner->getConnection() !== (new Metafield)->getConnection()) {
            throw new TenantBoundaryViolation('Metafield owners must use the canonical tenant connection.');
        }
        $query = (new ($owner::class))->newQuery()->whereKey($owner->getKey());
        MetafieldOwnerPredicate::equal($query, $query->getModel()->getQualifiedKeyName(), ModelIdentifier::required($owner));
        if (MetafieldOwnership::active()) {
            $resource = MetafieldOwnership::resource($owner);
            $this->boundary->query($query, $resource);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $canonical = $query->first();
        if (! $canonical instanceof Model) {
            if (MetafieldOwnership::active()) {
                throw new TenantBoundaryViolation('The metafield owner is unavailable in the current tenant boundary.');
            }

            return $query->firstOrFail();
        }

        return $canonical;
    }
}
