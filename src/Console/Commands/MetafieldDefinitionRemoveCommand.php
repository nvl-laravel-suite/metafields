<?php

declare(strict_types=1);

namespace Nvl\Metafields\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Removes a metafield definition and optionally clears all owner values.
 */
final class MetafieldDefinitionRemoveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nvl:metafields:definition-remove {handle : The handle (namespace.key)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a metafield definition and all associated values';

    /**
     * Execute the console command.
     *
     * @param  DeleteMetafieldDefinitionAction  $action  Definition deletion action
     * @return int Console exit code
     */
    public function handle(DeleteMetafieldDefinitionAction $action): int
    {
        $handle = (string) $this->argument('handle');
        $definition = MetafieldDefinition::query()
            ->active()
            ->where('active_handle', $handle)
            ->first();

        if (! $definition instanceof MetafieldDefinition) {
            $this->error("Metafield definition not found: {$handle}");

            return Command::FAILURE;
        }

        if (! $this->confirm("Are you sure you want to delete '{$handle}' and ALL its values?")) {
            return Command::SUCCESS;
        }

        $action->execute($definition, $definition->revision, true);
        $this->info("Successfully removed metafield definition: {$handle}");

        return Command::SUCCESS;
    }
}
