<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;

/**
 * Reads active definition assignments for registered owner types and sections.
 */
final class OwnerMetafieldAssignmentCatalog
{
    /**
     * Resolve every active assignment for an owner type.
     *
     * @return Collection<string, MetafieldDefinitionAssignment>
     */
    public function activeForOwnerType(string $ownerType): Collection
    {
        /** @var Collection<string, MetafieldDefinitionAssignment> $assignments */
        $assignments = MetafieldDefinitionAssignment::query()
            ->with('definition')
            ->whereHas('definition', static function (Builder $query): void {
                $query->whereNotNull('active_handle');
            })
            ->where('owner_type', $ownerType)
            ->where('is_active', true)
            ->get()
            ->keyBy('definition_id');

        return $assignments;
    }
}
