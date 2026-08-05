<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\OwnerMetafieldRedirectTarget;

final readonly class OwnerMetafieldRedirectResolver
{
    public function __construct(
        private MetafieldOwnerRegistry $ownerRegistry,
    ) {}

    public function resolveShowTarget(Model $owner): OwnerMetafieldRedirectTarget
    {
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);

        if (! $this->ownerRegistry->supportsRuntimeEditing($ownerType)) {
            throw new InvalidArgumentException(
                "Owner runtime metafield management is not enabled for [{$ownerType}].",
            );
        }

        $configuration = $this->ownerRegistry->configurationForType($ownerType);
        $route = $configuration['show_route'] ?? null;
        $parameterSources = $configuration['show_route_parameters'] ?? null;

        if (! is_string($route) || $route === '' || ! is_array($parameterSources)) {
            throw new InvalidArgumentException(
                "Owner redirect configuration is missing for [{$ownerType}].",
            );
        }

        $parameters = [];

        foreach ($parameterSources as $parameter => $source) {
            $value = data_get($owner, $source);

            if ((! is_string($value) && ! is_int($value))
                || trim((string) $value) === '') {
                throw new InvalidArgumentException(
                    "Owner redirect parameter [{$parameter}] is missing for [{$ownerType}].",
                );
            }

            $parameters[$parameter] = (string) $value;
        }

        return new OwnerMetafieldRedirectTarget($route, $parameters);
    }
}
