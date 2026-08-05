<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;

/**
 * Builds consumer-safe assigned field payloads for one registered owner.
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
     * List active assigned fields and current values for an owner.
     *
     * @return Collection<int, OwnerMetafieldField>
     */
    public function list(Model $owner, ?string $locale = null): Collection
    {
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);

        /** @var Collection<int, MetafieldDefinitionAssignment> $assignments */
        $assignments = MetafieldDefinitionAssignment::query()
            ->with('definition.translations')
            ->where('owner_type', $ownerType)
            ->where('is_active', true)
            ->whereHas('definition', static function (Builder $query): void {
                $query->whereNotNull('active_handle');
            })
            ->orderBy('display_order')
            ->get();

        if ($assignments->isEmpty()) {
            /** @var Collection<int, OwnerMetafieldField> $empty */
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
        /** @var list<OwnerMetafieldField> $fieldItems */
        $fieldItems = $assignments
            ->map(
                static fn (MetafieldDefinitionAssignment $assignment): OwnerMetafieldField => OwnerMetafieldField::fromAssignment(
                    assignment: $assignment,
                    metafield: $metafields->get($assignment->definition_id),
                    locale: $locale,
                ),
            )
            ->values()
            ->all();

        /** @var Collection<int, OwnerMetafieldField> $fields */
        $fields = new Collection($fieldItems);

        return $fields;
    }
}
