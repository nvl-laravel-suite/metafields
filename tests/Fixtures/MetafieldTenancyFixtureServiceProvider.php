<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers only the test-owned canonical Metafield owner resource and adapter. */
final class MetafieldTenancyFixtureServiceProvider extends ServiceProvider
{
    /** Register fixture ownership before final configuration validation. */
    public function boot(TenantResourceRegistry $resources, TenantAdoptionRegistry $adoptions): void
    {
        $resources->register(new TenantResourceDefinition(
            'test.metafield-owners',
            'test.metafield-owners',
            TestMetafieldOwner::class,
        ));
        $adoptions->register('resource-fixture-owners', TenancyOwnerAdoptionAdapter::class);
    }
}
