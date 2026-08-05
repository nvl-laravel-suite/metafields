<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

final readonly class OwnerMetafieldRedirectTarget
{
    /**
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public string $route,
        public array $parameters,
    ) {}
}
