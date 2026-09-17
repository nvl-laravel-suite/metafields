<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Tenancy\Contracts\TenantParentResolver;

/** Exposes the package's configured canonical owner allowlist to Tenancy. */
final readonly class MetafieldTenantParentResolver implements TenantParentResolver
{
    /** Create the parent resolver. */
    public function __construct(private MetafieldOwnerRegistry $owners) {}

    /** @return array<string, class-string<Model>> */
    public function types(): array
    {
        $types = [];
        foreach ($this->owners->all() as $configuration) {
            $model = new $configuration['model'];
            $types[$model->getMorphClass()] = $configuration['model'];
        }

        return $types;
    }
}
