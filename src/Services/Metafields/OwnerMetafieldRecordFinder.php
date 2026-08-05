<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Exceptions\MetafieldIntegrityException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Support\ModelIdentifier;

/**
 * OwnerMetafieldRecordFinder
 *
 * Resolves current and restorable owner metafield rows while enforcing the
 * single-active-row invariant.
 */
final class OwnerMetafieldRecordFinder
{
    /**
     * Find and lock one active owner metafield record.
     */
    public function findCurrent(Model $owner, string $definitionId): ?Metafield
    {
        /** @var Collection<int, Metafield> $records */
        $records = $this->activeQuery($owner)
            ->lockForUpdate()
            ->where('definition_id', $definitionId)
            ->get();

        $this->ensureNoDuplicateActiveRecords($owner, $records);

        /** @var Metafield|null $record */
        $record = $records->first();

        return $record;
    }

    /**
     * Find the active record or the most recently cleared reusable record.
     */
    public function findPreferredExisting(Model $owner, string $definitionId): ?Metafield
    {
        $current = $this->findCurrent($owner, $definitionId);

        if ($current instanceof Metafield) {
            return $current;
        }

        /** @var Metafield|null $trashedRecord */
        $trashedRecord = $this->trashedQuery($owner)
            ->lockForUpdate()
            ->where('definition_id', $definitionId)
            ->orderByDesc('deleted_at')
            ->orderByDesc('updated_at')
            ->first();

        return $trashedRecord;
    }

    /**
     * Map active owner records by definition.
     *
     * @param  list<string>  $definitionIds
     * @return Collection<string, Metafield>
     */
    public function mapCurrentByDefinitionIds(
        Model $owner,
        array $definitionIds,
        bool $lockForUpdate = false,
    ): Collection {
        if ($definitionIds === []) {
            /** @var Collection<string, Metafield> $empty */
            $empty = collect();

            return $empty;
        }

        $query = $this->activeQuery($owner)
            ->with(['definition.translations', 'translations'])
            ->whereIn('definition_id', $definitionIds);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, Metafield> $records */
        $records = $query->get();

        /** @var list<string> $duplicateDefinitionIds */
        $duplicateDefinitionIds = array_values($records
            ->groupBy('definition_id')
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->keys()
            ->map(static fn (mixed $definitionId): string => (string) $definitionId)
            ->values()
            ->all());

        if ($duplicateDefinitionIds !== []) {
            throw MetafieldIntegrityException::duplicateActiveOwnerDefinitionRecords(
                owner: $owner,
                definitionIds: $duplicateDefinitionIds,
            );
        }

        /** @var Collection<string, Metafield> $mapped */
        $mapped = $records->keyBy('definition_id');

        return $mapped;
    }

    /**
     * @return Builder<Metafield>
     */
    private function activeQuery(Model $owner): Builder
    {
        return Metafield::query()
            ->where('metafieldable_type', $owner->getMorphClass())
            ->where('metafieldable_id', ModelIdentifier::required($owner));
    }

    /**
     * @return Builder<Metafield>
     */
    private function trashedQuery(Model $owner): Builder
    {
        return Metafield::query()
            ->onlyTrashed()
            ->where('metafieldable_type', $owner->getMorphClass())
            ->where('metafieldable_id', ModelIdentifier::required($owner));
    }

    /**
     * @param  Collection<int, Metafield>  $records
     */
    private function ensureNoDuplicateActiveRecords(Model $owner, Collection $records): void
    {
        if ($records->count() <= 1) {
            return;
        }

        /** @var list<string> $definitionIds */
        $definitionIds = $records
            ->pluck('definition_id')
            ->unique()
            ->values()
            ->all();

        throw MetafieldIntegrityException::duplicateActiveOwnerDefinitionRecords(
            owner: $owner,
            definitionIds: $definitionIds,
        );
    }
}
