<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;

/**
 * Defines the supported revoke metafield definition tenant grant workflow.
 *
 * @api
 */
interface RevokeMetafieldDefinitionTenantGrantContract
{
    /** Revoke one exact grant revision. */
    public function execute(string $grantId, int $expectedRevision): MetafieldDefinitionTenantGrant;
}
