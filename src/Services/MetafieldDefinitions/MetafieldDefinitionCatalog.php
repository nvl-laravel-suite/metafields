<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * MetafieldDefinitionCatalog
 *
 * Read-only catalog for resolving metafield definitions used by admin flows
 * and owner compatibility helpers.
 */
final class MetafieldDefinitionCatalog
{
    /**
     * @return Collection<int, MetafieldDefinition>
     */
    public function list(): Collection
    {
        /** @var Collection<int, MetafieldDefinition> $definitions */
        $definitions = MetafieldDefinition::query()
            ->active()
            ->whereHas('assignments', static function (Builder $query): void {
                $query->whereRaw('is_active = ?', [true]);
            })
            ->with([
                'translations',
                'assignments' => static function (Relation $query): void {
                    $query->whereRaw('is_active = ?', [true]);
                },
            ])
            ->orderBy('display_order')
            ->orderBy('namespace')
            ->orderBy('key')
            ->get();

        return $definitions;
    }

    public function findByHandle(string $handle): ?MetafieldDefinition
    {
        /** @var MetafieldDefinition|null $definition */
        $definition = MetafieldDefinition::query()
            ->active()
            ->with('translations')
            ->where('active_handle', $handle)
            ->first();

        return $definition;
    }

    /**
     * @param  list<string>  $handles
     * @return Collection<string, string>
     */
    public function mapIdsByHandles(array $handles): Collection
    {
        if ($handles === []) {
            /** @var Collection<string, string> $empty */
            $empty = collect();

            return $empty;
        }

        /** @var Collection<string, string> $definitionIdsByHandle */
        $definitionIdsByHandle = MetafieldDefinition::query()
            ->active()
            ->whereIn('active_handle', $handles)
            ->pluck('id', 'active_handle');

        return $definitionIdsByHandle;
    }
}
