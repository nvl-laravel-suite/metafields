<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Defines the supported grant metafield definition to tenant workflow.
 *
 * @api
 */
interface GrantMetafieldDefinitionToTenantContract
{
    /** Grant or refresh the exact platform source revision. */
    public function execute(string $definitionId, TenantId $recipient, int $sourceRevision): MetafieldDefinitionTenantGrant;
}
