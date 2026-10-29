<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;

/**
 * Builds consumer-safe assigned field payloads for one registered owner.
 *
 * @internal
 */
final readonly class OwnerMetafieldFieldCatalog
{
    /**
     * Create the owner field catalog.
     */
    public function __construct(
        private MetafieldOwnerRegistry $ownerRegistry,
        private OwnerMetafieldRecordFinder $recordFinder,
    ) {}

    /**
     * List active assigned fields and current values for an admitted canonical owner.
     *
     * @return Collection<int, array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}>
     */
    public function list(Model $owner, ?string $locale = null): Collection
    {
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);

        $query = MetafieldDefinitionAssignment::query()
            ->with('definition.translations')
            ->where('is_active', true)
            ->whereHas('definition', static function (Builder $query): void {
                $query->whereNotNull('active_handle');
            })
            ->orderBy('display_order');
        MetafieldOwnerPredicate::equal($query, 'owner_type', $ownerType);
        $assignments = $query->get();

        if ($assignments->isEmpty()) {
            /** @var Collection<int, array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var list<string> $definitionIds */
        $definitionIds = $assignments
            ->pluck('definition_id')
            ->values()
            ->all();
        /** @var Collection<string, Metafield> $metafields */
        $metafields = $this->recordFinder->mapCurrentByDefinitionIds($owner, $definitionIds);
        /** @var list<array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}> $fieldItems */
        $fieldItems = $assignments
            ->map(
                static fn (MetafieldDefinitionAssignment $assignment): array => [
                    'definition' => $assignment->definition,
                    'assignment' => $assignment,
                    'value' => $metafields->get($assignment->definition_id),
                ],
            )
            ->values()
            ->all();

        /** @var Collection<int, array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}> $fields */
        $fields = new Collection($fieldItems);

        return $fields;
    }
}
