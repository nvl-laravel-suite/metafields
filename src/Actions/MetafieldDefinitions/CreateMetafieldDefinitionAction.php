<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Contracts\CreateMetafieldDefinitionContract;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter;

/**
 * CreateMetafieldDefinitionAction
 *
 * Orchestrates the creation of a new metafield definition.
 */
final class CreateMetafieldDefinitionAction implements CreateMetafieldDefinitionContract
{
    public function __construct(
        private readonly MetafieldDefinitionWriter $writer,
        private readonly MetafieldDefinitionAssignmentSyncer $assignmentSyncer,
    ) {}

    /**
     * Execute the use-case.
     */
    public function execute(CreateMetafieldDefinitionPayload $data): MetafieldDefinition
    {
        return DB::transaction(function () use ($data) {
            $definition = $this->writer->create($data);
            $this->assignmentSyncer->sync($definition, $data->assignment);

            return $definition;
        });
    }
}
