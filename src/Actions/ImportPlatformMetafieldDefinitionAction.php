<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions;

use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogReader;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionImporter;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\EffectiveTenantConnection;

/** Imports a granted platform definition as one independent tenant schema. */
final readonly class ImportPlatformMetafieldDefinitionAction
{
    /** Create the standalone catalog import action. */
    public function __construct(
        private MetafieldDefinitionCatalogReader $reader,
        private MetafieldDefinitionImporter $importer,
        private EffectiveTenantConnection $connections,
    ) {}

    /** Import the exact requested grant/source revision atomically. */
    public function execute(ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition
    {
        $snapshot = $this->reader->find($data->grantId);
        if ($snapshot->grantRevision !== $data->expectedGrantRevision
            || $snapshot->sourceRevision !== $data->expectedSourceRevision) {
            throw new TenantBoundaryViolation('The requested Metafield catalog revision is stale.');
        }

        return $this->connections->core()->transaction(
            fn (): MetafieldDefinition => $this->importer->persist($snapshot, $data),
        );
    }
}
