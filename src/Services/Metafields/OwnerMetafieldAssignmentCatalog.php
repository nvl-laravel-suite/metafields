<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerScope;

/**
 * Reads active definition assignments for registered owner types and sections.
 *
 * @api
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
        $query = MetafieldDefinitionAssignment::query()
            ->with('definition')
            ->whereHas('definition', static function (Builder $query): void {
                $query->whereNotNull('active_handle');
            })
            ->where('is_active', true);
        MetafieldOwnerPredicate::equal($query, 'owner_type', $ownerType);
        $assignments = $query->get()->keyBy('definition_id');

        return $assignments;
    }

    /** @return Collection<string, MetafieldDefinitionAssignment> */
    public function activeForOwner(string $ownerType, Model $owner): Collection
    {
        $key = MetafieldOwnerScope::key($owner);

        return $this->activeForOwnerType($ownerType)->filter(static fn (MetafieldDefinitionAssignment $assignment): bool => MetafieldOwnerScope::accepts($assignment->owner_scopes, $key));
    }
}
