<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Contracts\DeleteMetafieldDefinitionContract;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionRemover;

/**
 * DeleteMetafieldDefinitionAction
 *
 * Orchestrates the soft deletion of a metafield definition.
 */
final class DeleteMetafieldDefinitionAction implements DeleteMetafieldDefinitionContract
{
    public function __construct(
        private readonly MetafieldDefinitionRemover $remover,
    ) {}

    /**
     * Execute the use-case.
     */
    public function execute(
        MetafieldDefinition|string $definition,
        int $expectedRevision,
        bool $deleteValues = false,
    ): bool {
        return DB::transaction(function () use ($definition, $deleteValues, $expectedRevision): bool {
            $definitionId = $definition instanceof MetafieldDefinition
                ? $definition->id
                : $definition;
            $definition = MetafieldDefinition::query()
                ->lockForUpdate()
                ->findOrFail($definitionId);

            if ($definition->revision !== $expectedRevision) {
                throw StaleMetafieldVersionException::forResource(
                    'metafield definition',
                    $definition->id,
                );
            }

            return $this->remover->delete($definition, $deleteValues);
        });
    }
}
