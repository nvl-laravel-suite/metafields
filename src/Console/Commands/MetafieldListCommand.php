<?php

declare(strict_types=1);

namespace Nvl\Metafields\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * MetafieldListCommand
 *
 * Command to list all defined metafields by namespace.
 */
final class MetafieldListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nvl:metafields:list {namespace? : The namespace (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all defined metafields';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $namespace = $this->argument('namespace');

        $query = MetafieldDefinition::query()->active();
        if ($namespace) {
            $query->where('namespace', $namespace);
        }

        $definitions = $query->orderBy('namespace')->orderBy('display_order')->get();

        if ($definitions->isEmpty()) {
            $this->warn('No metafield definitions found.');

            return Command::SUCCESS;
        }

        $headers = ['Handle', 'Type', 'Title', 'Translatable', 'Required'];
        $data = $definitions->map(fn (MetafieldDefinition $definition): array => [
            $definition->handle,
            $definition->type->value,
            $definition->displayTitle(),
            $definition->is_translatable ? 'Yes' : 'No',
            $definition->is_required ? 'Yes' : 'No',
        ])->toArray();

        $this->table($headers, $data);

        return Command::SUCCESS;
    }
}
