<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use InvalidArgumentException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Compiles explicit definition and canonical host-value ownership.
 *
 * @internal
 */
final readonly class MetafieldResourceRegistrar
{
    public function __construct(private MetafieldModelRegistry $models) {}

    public function register(OwnershipResourceRegistry $resources): void
    {
        $class = $this->models->definitionClass();
        $owned = $class::ownershipDefinition()->strategy !== 'unscoped';
        MetafieldOwnership::assertCompatibleHosts($owned);
        $central = $owned && $class::allowsCentralCatalog();
        $parents = config('nvl-metafields.ownership.owner_resources', []);
        if (! is_array($parents) || ! array_is_list($parents)) {
            throw new InvalidArgumentException('Metafield hosts require explicit resource keys.');
        }
        foreach ($parents as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Metafield host resource.');
            }
        }
        $resources->register(new OwnershipResourceDefinition('metafields.definitions', 'metafields', $class, $class::ownershipDefinition(), allowsCentral: $central, immutableFields: $owned ? ['catalog_import_key', 'catalog_source_id', 'catalog_source_revision', 'catalog_source_hash', 'catalog_import_request_hash'] : []));
        foreach (['metafields.definition-assignments' => [MetafieldDefinitionAssignment::class, 'definition', 'metafields.definitions'], 'metafields.definition-translations' => [MetafieldDefinitionTranslation::class, 'definition', 'metafields.definitions'], 'metafields.value-translations' => [MetafieldTranslation::class, 'metafield', 'metafields.values']] as $key => [$model,$relation,$parent]) {
            $resources->register(new OwnershipResourceDefinition($key, 'metafields', $model, $owned ? OwnershipDefinition::inherited($relation) : OwnershipDefinition::unscoped(), parentResource: $owned ? $parent : null, allowsCentral: $key !== 'metafields.value-translations' && $central));
        }
        $resources->register(new OwnershipResourceDefinition('metafields.values', 'metafields', Metafield::class, $owned ? OwnershipDefinition::inherited('metafieldable') : OwnershipDefinition::unscoped(), parentResources: $owned ? $parents : [], immutableFields: $owned ? ['definition_id'] : []));
        $resources->register(new OwnershipResourceDefinition('metafields.catalog-grants', 'metafields', $owned ? MetafieldDefinitionOwnerGrant::class : MetafieldDefinitionTenantGrant::class, $owned ? OwnershipDefinition::captured() : OwnershipDefinition::unscoped(), immutableFields: $owned ? ['definition_id'] : []));
    }
}
