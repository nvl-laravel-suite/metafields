<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Support\Carbon;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/**
 * Recipient-owned capability to copy one central definition revision.
 *
 * @property string $id
 * @property string $definition_id
 * @property int $source_revision
 * @property int $revision
 * @property bool $enabled
 * @property Carbon|null $revoked_at
 * @property string|null $tenant_id
 * @property string $owner_scope_key
 * @property string $owner_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @nvl-consumer-read id
 * @nvl-consumer-read definition_id
 * @nvl-consumer-read source_revision
 * @nvl-consumer-read revision
 * @nvl-consumer-read enabled
 * @nvl-consumer-read revoked_at
 *
 * @api
 */
final class MetafieldDefinitionOwnerGrant extends MetafieldDefinitionTenantGrant
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }
}
