<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantNotFound;
use Nvl\Tenancy\ValueObjects\TenantDescriptor;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Resolves the two active Metafield fixture tenants. */
final class MetafieldTenancyDirectory implements TenantDirectory
{
    /** Resolve one fixture tenant. */
    public function find(TenantId $tenant): TenantDescriptor
    {
        if (! in_array($tenant->value, [MetafieldTenancyScenario::A, MetafieldTenancyScenario::B], true)) {
            throw new TenantNotFound;
        }

        return new TenantDescriptor($tenant, TenantStatus::Active);
    }
}
