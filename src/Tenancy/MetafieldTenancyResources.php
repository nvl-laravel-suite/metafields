<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Exceptions\TenantConfigurationInvalid;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\ValueObjects\TenantResourceDefinition;

/** Registers Metafields' immutable complete ownership graph. */
final readonly class MetafieldTenancyResources
{
    /** Create the configuration-backed registrar. */
    public function __construct(private Repository $configuration) {}

    /** Register definition and canonical-owner value graphs. */
    public function register(TenantResourceRegistry $resources): void
    {
        $sharing = $this->configuration->get('tenancy.sharing.metafields', 'none');
        $mode = $this->configuration->get('tenancy.resources.metafields', 'tenant');
        if (! in_array($sharing, ['none', 'copy'], true) || ! in_array($mode, ['tenant', 'platform'], true)) {
            throw new TenantConfigurationInvalid('Metafields tenancy configuration is invalid.');
        }

        $mixed = $sharing === 'copy';
        $platform = $mode === 'platform';
        $resources->registerParentResolver('metafields.values', MetafieldTenantParentResolver::class);
        foreach ([
            new TenantResourceDefinition('metafields.definitions', 'metafields', MetafieldDefinition::class, allowsPlatformCatalog: $mixed, allowsPlatformRows: $platform),
            new TenantResourceDefinition('metafields.definition-assignments', 'metafields', MetafieldDefinitionAssignment::class, TenantResourceKind::Inherited, 'metafields.definitions', 'definition'),
            new TenantResourceDefinition('metafields.definition-translations', 'metafields', MetafieldDefinitionTranslation::class, TenantResourceKind::Inherited, 'metafields.definitions', 'definition'),
            new TenantResourceDefinition('metafields.values', 'metafields', Metafield::class, TenantResourceKind::Inherited, null, 'metafieldable'),
            new TenantResourceDefinition('metafields.value-translations', 'metafields', MetafieldTranslation::class, TenantResourceKind::Inherited, 'metafields.values', 'metafield'),
            new TenantResourceDefinition('metafields.catalog-grants', 'metafields.catalog-grants', MetafieldDefinitionTenantGrant::class),
        ] as $definition) {
            $resources->register($definition);
        }
    }
}
