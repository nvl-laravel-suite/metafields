<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

/** Host policy authorizes issuance and revocation for the admitted native recipient. @api */
interface MetafieldCatalogAccess
{
    public function assertCanGrant(string $sourceId, int $revision): void;

    public function assertCanRevoke(string $grantId): void;
}
