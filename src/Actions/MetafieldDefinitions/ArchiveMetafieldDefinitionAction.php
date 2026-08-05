<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Data\ArchiveMetafieldDefinitionPayload;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Archives or restores a definition while preserving historical values.
 */
final class ArchiveMetafieldDefinitionAction
{
    /**
     * Archive or restore a definition through its expected revision.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        ArchiveMetafieldDefinitionPayload $data,
    ): MetafieldDefinition {
        return DB::transaction(function () use ($definition, $data): MetafieldDefinition {
            $definitionId = $definition instanceof MetafieldDefinition
                ? $definition->id
                : $definition;
            $definition = MetafieldDefinition::query()
                ->lockForUpdate()
                ->findOrFail($definitionId);

            if ($definition->revision !== $data->expectedRevision) {
                throw StaleMetafieldVersionException::forResource(
                    'metafield definition',
                    $definition->id,
                );
            }

            if (! $data->archived && MetafieldDefinition::query()
                ->active()
                ->where('active_handle', $definition->handle)
                ->where('id', '!=', $definition->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'archived' => [
                        trans(
                            'metafields::metafields/validation.custom.definition.active_handle_conflict',
                        ),
                    ],
                ]);
            }

            $definition->archived_at = $data->archived ? now() : null;
            $definition->save();

            return $definition->refresh()->load(['assignments', 'translations']);
        });
    }
}
