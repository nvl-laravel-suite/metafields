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
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Resolves configured metafield owner models without leaking persistence into controllers.
 *
 * @internal
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

    /** Reload one programmatic owner through its registered resource boundary. */
    public function canonical(Model $owner, bool $lock = false): Model
    {
        MetafieldOwnership::declared();
        $prototype = new ($owner::class);
        $key = ModelIdentifier::required($owner);
        $originalKey = $owner->getRawOriginal($prototype->getKeyName());
        if (! $owner->exists
            || (! is_string($originalKey) && ! is_int($originalKey))
            || $owner->getTable() !== $prototype->getTable()
            || ($owner->getConnectionName() !== null && $owner->getConnectionName() !== $prototype->getConnection()->getName())
            || $owner->getConnection() !== $prototype->getConnection()
            || $owner->getKeyName() !== $prototype->getKeyName()
            || $owner->getKeyType() !== $prototype->getKeyType()
            || $owner->isDirty($prototype->getKeyName())
            || (string) $originalKey !== $key) {
            throw new OwnershipViolation('The metafield owner identity is not canonical.');
        }
        if ($prototype->getConnection() !== (new Metafield)->getConnection()) {
            throw new OwnershipViolation('Metafield owners must use the canonical package connection.');
        }
        $query = $prototype->newQuery()->useWritePdo()->whereKey($key);
        MetafieldOwnerPredicate::equal($query, $prototype->getQualifiedKeyName(), $key);
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
                throw new OwnershipViolation('The metafield owner is unavailable in the current owner boundary.');
            }

            return $query->firstOrFail();
        }

        return $canonical;
    }
}
