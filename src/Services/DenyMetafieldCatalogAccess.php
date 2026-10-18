<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Denies central-copy grant administration until the host supplies a policy. @internal */
final readonly class DenyMetafieldCatalogAccess implements MetafieldCatalogAccess
{
    public function assertCanGrant(string $sourceId, int $revision): void
    {
        throw new OwnershipViolation('Metafield catalog issuance requires host authorization.');
    }

    public function assertCanRevoke(string $grantId): void
    {
        throw new OwnershipViolation('Metafield catalog revocation requires host authorization.');
    }
}
