<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;

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
        return $modelClass::query()->findOrFail($ownerId);
    }
}
