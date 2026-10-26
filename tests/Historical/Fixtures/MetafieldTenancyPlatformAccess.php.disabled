<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Tenancy\Contracts\PlatformAccess;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\PlatformOperation;

/** Authorizes only the fixture's adoption and catalog operations. */
final readonly class MetafieldTenancyPlatformAccess implements PlatformAccess
{
    /** Authorize an exact fixture operation. */
    public function authorize(PlatformOperation $operation): void
    {
        if (! in_array($operation->purpose, ['fixture.adoption', 'fixture.catalog'], true)
            || $operation->actorType !== 'test' || $operation->actorId !== 'fixture') {
            throw new TenantBoundaryViolation;
        }
    }
}
