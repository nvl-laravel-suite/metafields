<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tenancy;

use Closure;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Enums\TenantContextMode;

/** Retains only the explicit V1 platform grant writer while rejecting adopted storage. @internal */
final readonly class LegacyMetafieldGrantPersistence
{
    public function __construct(private TenantContext $context, private PersistedOwnershipStorage $storage) {}

    /**
     * @param  Closure():bool  $native
     * @param  Closure():bool  $guarded
     */
    public function save(MetafieldDefinitionTenantGrant $grant, Closure $native, Closure $guarded): bool
    {
        if (! MetafieldOwnership::declared() && config('nvl-tenancy.enabled') === true && $this->context->snapshot()->mode === TenantContextMode::Platform) {
            $this->storage->assertUnadopted($grant->getConnection(), 'metafields.catalog-grants', $grant->getTable());

            return $native();
        }

        return $guarded();
    }
}
