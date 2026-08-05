<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Contracts\SyncOwnerMetafieldsContract;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Data\SyncOwnerMetafieldValuePayload;
use Nvl\Metafields\Events\MetafieldsSyncedEvent;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordFinder;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordWriter;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldSyncValidator;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Spatie\LaravelData\Optional;

/**
 * Synchronizes assigned metafield values for a polymorphic owner model.
 *
 * The action enforces owner assignment, required-section completeness,
 * validation, soft-delete restoration, translation writes, and typed value
 * storage in one transaction.
 */
final class SyncOwnerMetafieldsAction implements SyncOwnerMetafieldsContract
{
    public function __construct(
        private readonly MetafieldOwnerRegistry $ownerRegistry,
        private readonly OwnerMetafieldAssignmentCatalog $assignmentCatalog,
        private readonly OwnerMetafieldRecordFinder $recordFinder,
        private readonly OwnerMetafieldSyncValidator $syncValidator,
        private readonly OwnerMetafieldRecordWriter $recordWriter,
    ) {}

    /**
     * Sync all provided owner metafield items.
     *
     * @param  Model  $owner  Polymorphic owner receiving the values
     * @param  SyncOwnerMetafieldsPayload  $data  Validated sync payload
     * @return Collection<int, Metafield>
     */
    public function execute(Model $owner, SyncOwnerMetafieldsPayload $data): Collection
    {
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);
        $items = $data->items->toCollection()->values();
        /** @var list<string> $definitionIds */
        $definitionIds = array_values($items
            ->map(static fn (SyncOwnerMetafieldValuePayload $item): string => $item->definitionId)
            ->all());
        $this->syncValidator->ensureDefinitionIdsAreUnique($definitionIds);

        sort($definitionIds);

        $synced = (new Metafield)->getConnection()->transaction(function () use (
            $definitionIds,
            $items,
            $owner,
            $ownerType,
        ): Collection {
            $ownerAssignments = $this->assignmentCatalog->activeForOwnerType($ownerType);
            $assignments = $ownerAssignments->filter(
                static fn (MetafieldDefinitionAssignment $assignment): bool => in_array(
                    $assignment->definition_id,
                    $definitionIds,
                    true,
                ),
            );
            $this->syncValidator->ensureAssignmentsPresent($definitionIds, $assignments);
            /** @var list<string> $ownerDefinitionIds */
            $ownerDefinitionIds = $ownerAssignments->keys()->all();
            sort($ownerDefinitionIds);

            MetafieldDefinition::query()
                ->active()
                ->whereKey($ownerDefinitionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $ownerAssignments = $this->assignmentCatalog->activeForOwnerType($ownerType);
            $assignments = $ownerAssignments->filter(
                static fn (MetafieldDefinitionAssignment $assignment): bool => in_array(
                    $assignment->definition_id,
                    $definitionIds,
                    true,
                ),
            );
            $this->syncValidator->ensureAssignmentsPresent($definitionIds, $assignments);
            /** @var list<string> $sections */
            $sections = $assignments
                ->pluck('section')
                ->filter(static fn (mixed $section): bool => is_string($section) && $section !== '')
                ->unique()
                ->sort()
                ->values()
                ->all();
            $sectionAssignments = $ownerAssignments
                ->filter(
                    static fn (MetafieldDefinitionAssignment $assignment): bool => in_array(
                        $assignment->section,
                        $sections,
                        true,
                    ),
                );
            /** @var list<string> $relevantDefinitionIds */
            $relevantDefinitionIds = array_values(array_unique([
                ...$definitionIds,
                ...$sectionAssignments->keys()->all(),
            ]));
            sort($relevantDefinitionIds);
            $currentRecords = $this->recordFinder->mapCurrentByDefinitionIds(
                $owner,
                $relevantDefinitionIds,
                lockForUpdate: true,
            );
            $this->syncValidator->ensureRequiredAssignmentsPresent(
                $definitionIds,
                $sectionAssignments,
                $currentRecords,
            );

            /** @var Collection<int, Metafield> $syncedMetafields */
            $syncedMetafields = collect();

            foreach ($items as $index => $item) {
                /** @var MetafieldDefinitionAssignment $assignment */
                $assignment = $assignments->get($item->definitionId);
                $definition = $assignment->definition;

                if (! $definition instanceof MetafieldDefinition) {
                    throw ValidationException::withMessages([
                        "items.{$index}.definitionId" => [
                            trans('metafields::owner-metafields/validation.custom.definitionId.missing_definition'),
                        ],
                    ]);
                }

                $this->syncValidator->ensureDefinitionCanSync($definition, $ownerType, $index);
                $shouldClear = ! ($item->clear instanceof Optional) && $item->clear === true;
                $this->syncValidator->ensureRequiredAssignmentCanClear(
                    assignment: $assignment,
                    definition: $definition,
                    shouldClear: $shouldClear,
                    index: $index,
                );
                $metafield = $currentRecords->get($definition->id);

                $this->syncValidator->ensureExpectedRevision(
                    $metafield,
                    $item->expectedRevision,
                    $definition->id,
                    $index,
                );

                if (! $metafield instanceof Metafield && ! $shouldClear) {
                    $metafield = $this->recordFinder->findPreferredExisting($owner, $definition->id);
                }

                if ($shouldClear) {
                    $this->recordWriter->clear($metafield);

                    continue;
                }

                if ($definition->is_translatable) {
                    $this->syncValidator->validateTranslations(
                        $item,
                        $definition,
                        $assignment,
                        $owner,
                        $index,
                    );
                    $translations = is_array($item->translations) ? $item->translations : [];

                    $metafield = $this->recordWriter->upsertRecord($metafield, $owner, $definition->id);
                    $this->recordWriter->syncTranslations(
                        metafield: $metafield,
                        translations: $translations,
                        definition: $definition,
                        mode: $item->translationMode,
                    );
                    $metafield->refresh()->loadMissing(['translations', 'definition']);
                    $syncedMetafields->push($metafield);

                    continue;
                }

                $this->syncValidator->validateNonTranslatableValue(
                    $item,
                    $definition,
                    $assignment,
                    $owner,
                    $index,
                );

                $metafield = $this->recordWriter->upsertRecord($metafield, $owner, $definition->id);
                $this->recordWriter->syncValue($metafield, $item->value);
                $metafield->refresh()->loadMissing('definition');
                $syncedMetafields->push($metafield);
            }

            return $syncedMetafields->values();
        }, MetafieldConfiguration::positiveInteger('metafields.transactions.attempts', 3));

        MetafieldsSyncedEvent::dispatch($owner, $synced);

        return $synced;
    }
}
