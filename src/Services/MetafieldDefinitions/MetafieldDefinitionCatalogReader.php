<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Data\MetafieldDefinitionCatalogSnapshot;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Ownership\MetafieldOwnerCatalog;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Support\Tenancy\Contracts\TenantContext;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/** Reads one explicitly granted platform definition into a scalar snapshot. */
final readonly class MetafieldDefinitionCatalogReader
{
    /** Create the narrow authorized reader. */
    public function __construct(private TenantContext $context, private MetafieldDefinitionCatalogProjector $projection, private MetafieldOwnerCatalog $catalog) {}

    /** Return the immutable authorized source snapshot. */
    public function find(string $grantId): MetafieldDefinitionCatalogSnapshot
    {
        if (MetafieldOwnership::declared()) {
            return $this->catalog->inspect($grantId);
        }
        $tenant = $this->context->requireTenant();
        $grant = MetafieldDefinitionTenantGrant::query()->whereKey($grantId)->first();
        if (! $grant instanceof MetafieldDefinitionTenantGrant || $grant->tenant_id !== $tenant->value
            || ! $grant->enabled || $grant->revoked_at !== null) {
            throw new TenantBoundaryViolation('The Metafield catalog grant is unavailable.');
        }
        $source = MetafieldModelRegistry::query()->withoutGlobalScopes()
            ->whereKey($grant->definition_id)
            ->whereNull('tenant_id')
            ->where('ownership_key', 'platform')
            ->whereNotNull('active_handle')
            ->first();
        if (! $source instanceof MetafieldDefinition || $source->revision !== $grant->source_revision) {
            throw new TenantBoundaryViolation('The granted platform definition revision is unavailable.');
        }

        $rows = MetafieldDefinitionTranslation::withoutGlobalScopes()->where('metafield_definition_id', $source->id)->where('ownership_key', 'platform')->orderBy('locale')->get();

        return $this->projection->project($source, $grant, $rows);
    }
}
