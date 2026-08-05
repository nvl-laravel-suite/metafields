<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\DeleteOwnerMetafieldContract;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Clears one owner metafield value through the canonical sync pipeline.
 *
 * Deletion is modeled as a bulk sync item with `clear=true` so assignment,
 * required-field, and soft-delete behavior stay identical to regular syncs.
 */
final class DeleteOwnerMetafieldAction implements DeleteOwnerMetafieldContract
{
    public function __construct(
        private readonly SyncOwnerMetafieldsAction $syncOwnerMetafieldsAction,
    ) {}

    /**
     * Remove the metafield value for the given owner and definition.
     *
     * @param  Model  $owner  Owner model that holds the metafield
     * @param  MetafieldDefinition|string  $definition  Definition instance or ID
     * @return bool True when the clear request was accepted by the sync pipeline
     */
    public function execute(
        Model $owner,
        MetafieldDefinition|string $definition,
        int $expectedRevision,
    ): bool {
        $definitionId = $definition instanceof MetafieldDefinition
            ? $definition->getKey()
            : $definition;

        $this->syncOwnerMetafieldsAction->execute($owner, SyncOwnerMetafieldsPayload::from([
            'items' => [
                [
                    'definitionId' => $definitionId,
                    'clear' => true,
                    'expectedRevision' => $expectedRevision,
                ],
            ],
        ]));

        return true;
    }
}
