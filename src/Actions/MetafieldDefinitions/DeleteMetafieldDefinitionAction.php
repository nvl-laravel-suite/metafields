<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\MetafieldDefinitions;

use Nvl\Metafields\Contracts\DeleteMetafieldDefinitionContract;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionRemover;
use Nvl\Metafields\Services\MetafieldModelRegistry;

/**
 * DeleteMetafieldDefinitionAction
 *
 * Orchestrates the soft deletion of a metafield definition.
 *
 * @api
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
        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(function () use ($definition, $deleteValues, $expectedRevision): bool {
            $definitionId = $definition instanceof MetafieldDefinition
                ? $definition->id
                : $definition;
            $definition = MetafieldModelRegistry::query()
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
