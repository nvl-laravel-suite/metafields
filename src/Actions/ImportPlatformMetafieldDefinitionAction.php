<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions;

use Nvl\Metafields\Contracts\ImportPlatformMetafieldDefinitionContract;
use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogReader;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionImporter;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

/**
 * Imports a granted platform definition as one independent tenant schema.
 *
 * @api
 */
final readonly class ImportPlatformMetafieldDefinitionAction implements ImportPlatformMetafieldDefinitionContract
{
    /** Create the standalone catalog import action. */
    public function __construct(
        private MetafieldDefinitionCatalogReader $reader,
        private MetafieldDefinitionImporter $importer,
    ) {}

    /** Import the exact requested grant/source revision atomically. */
    public function execute(ImportPlatformMetafieldDefinitionData $data): MetafieldDefinition
    {
        $snapshot = $this->reader->find($data->grantId);
        if ($snapshot->grantRevision !== $data->expectedGrantRevision
            || $snapshot->sourceRevision !== $data->expectedSourceRevision) {
            throw new TenantBoundaryViolation('The requested Metafield catalog revision is stale.');
        }

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(
            fn (): MetafieldDefinition => $this->importer->persist($snapshot, $data),
        );
    }
}
