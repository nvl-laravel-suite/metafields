<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Contracts\UpdateMetafieldDefinitionContract;
use Nvl\Metafields\Data\UpdateMetafieldDefinitionPayload;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionMutationGuard;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter;

/**
 * UpdateMetafieldDefinitionAction
 *
 * Orchestrates the update of an existing metafield definition.
 */
final class UpdateMetafieldDefinitionAction implements UpdateMetafieldDefinitionContract
{
    public function __construct(
        private readonly MetafieldDefinitionWriter $writer,
        private readonly MetafieldDefinitionAssignmentSyncer $assignmentSyncer,
        private readonly MetafieldDefinitionMutationGuard $mutationGuard,
    ) {}

    /**
     * Execute the use-case.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        UpdateMetafieldDefinitionPayload $data,
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

            $this->mutationGuard->ensureUpdateIsSafe($definition, $data);

            $definition = $this->writer->update($definition, $data);
            $this->assignmentSyncer->sync($definition, $data->assignment);

            return $definition;
        });
    }
}
