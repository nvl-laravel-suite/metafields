<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Models\Metafield;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;

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
        private readonly TenantResourceRegistry $resources,
        private readonly TenantBoundary $boundary,
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
        $definition = $this->resources->forModel($owner);
        $query = $owner->newQueryWithoutScopes()->whereKey($owner->getKey());
        $this->boundary->query($query, $definition->key);
        if ($lock) {
            $query->lockForUpdate();
        }
        $canonical = $query->firstOrFail();
        if ($canonical->getConnection() !== (new Metafield)->getConnection()) {
            throw new TenantBoundaryViolation('Metafield owners must use the canonical tenant connection.');
        }

        return $canonical;
    }
}
