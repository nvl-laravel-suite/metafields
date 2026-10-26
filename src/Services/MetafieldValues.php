<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Exceptions\InvalidMetafieldMutationException;
use Nvl\Metafields\Exceptions\MetafieldDefinitionNotFoundException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordFinder;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordWriter;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldSyncValidator;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Translatable\Enums\TranslationSyncMode;
use RuntimeException;
use TypeError;

/**
 * Synchronizes assigned metafield values for a polymorphic owner model.
 *
 * The service enforces owner assignment, required-section completeness,
 * validation, soft-delete restoration, translation writes, and typed value
 * storage in one transaction.
 *
 * @api
 *
 * @phpstan-type SyncItem array{definitionId:string,clear?:bool,value?:mixed,translations?:array<string,mixed>|null,translation_mode?:TranslationSyncMode,expected_revision?:int|null}
 */
final class MetafieldValues
{
    public function __construct(
        private readonly MetafieldOwnerRegistry $ownerRegistry,
        private readonly OwnerMetafieldAssignmentCatalog $assignmentCatalog,
        private readonly OwnerMetafieldRecordFinder $recordFinder,
        private readonly OwnerMetafieldSyncValidator $syncValidator,
        private readonly OwnerMetafieldRecordWriter $recordWriter,
        private readonly MetafieldOwnerModelResolver $ownerResolver,
        private DomainEventDispatcher $domainEvents,
        private MetafieldActorPolicy $actors,
        private MetafieldDefinitionCatalog $definitionCatalog,
    ) {}

    /**
     * Sync all provided owner metafield items.
     *
     * @param  Model  $owner  Polymorphic owner receiving the values
     * @param  list<array<string, mixed>>  $items  Validated sync items
     * @return Collection<int, Metafield>
     */
    public function sync(Model $owner, array $items, Authenticatable|SystemActor $actor): Collection
    {
        $actor = $this->actors->authorize($actor, 'write');
        $this->validateItems($items);
        /** @var list<array{definitionId:string,clear?:bool|0|1|'0'|'1',value?:mixed,translations?:array<string,mixed>|null,translation_mode?:TranslationSyncMode|string,expected_revision?:int|float|numeric-string|bool|null}> $validatedItems */
        $validatedItems = $items;
        $owner = $this->ownerResolver->canonical($owner);
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);
        $items = collect($validatedItems)->map(static function (array $item): array {
            if (array_key_exists('clear', $item)) {
                $item['clear'] = (bool) $item['clear'];
            }
            if (array_key_exists('expected_revision', $item) && $item['expected_revision'] !== null) {
                $item['expected_revision'] = (int) $item['expected_revision'];
            }
            if (is_string($item['translation_mode'] ?? null)) {
                $item['translation_mode'] = TranslationSyncMode::from($item['translation_mode']);
            }

            /** @var SyncItem $item */
            return $item;
        })->values();
        /** @var list<string> $definitionIds */
        $definitionIds = array_values($items
            ->map(static fn (array $item): string => $item['definitionId'])
            ->all());
        $this->syncValidator->ensureDefinitionIdsAreUnique($definitionIds);

        sort($definitionIds);

        $synced = (new Metafield)->getConnection()->transaction(function () use (
            $definitionIds,
            $items,
            $owner,
            $ownerType,
            $actor,
        ): Collection {
            $owner = $this->ownerResolver->canonical($owner, lock: true);
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

            MetafieldModelRegistry::query()
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
                $assignment = $assignments->get($item['definitionId']);
                $definition = $assignment->definition;

                if (! $definition instanceof MetafieldDefinition) {
                    throw ValidationException::withMessages([
                        "items.{$index}.definitionId" => [
                            trans('nvl-metafields::owner-metafields/validation.custom.definitionId.missing_definition'),
                        ],
                    ]);
                }

                $this->syncValidator->ensureDefinitionCanSync($definition, $ownerType, $index);
                $shouldClear = ($item['clear'] ?? false) === true;
                $this->syncValidator->ensureRequiredAssignmentCanClear(
                    assignment: $assignment,
                    definition: $definition,
                    shouldClear: $shouldClear,
                    index: $index,
                );
                $metafield = $currentRecords->get($definition->id);

                $this->syncValidator->ensureExpectedRevision(
                    $metafield,
                    $item['expected_revision'] ?? null,
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
                        $actor,
                        $index,
                    );
                    $translations = $item['translations'] ?? [];

                    $metafield = $this->recordWriter->upsertRecord($metafield, $owner, $definition->id);
                    $this->recordWriter->syncTranslations(
                        metafield: $metafield,
                        translations: $translations,
                        definition: $definition,
                        mode: ($item['translation_mode'] ?? TranslationSyncMode::Patch),
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
                    $actor,
                    $index,
                );

                $metafield = $this->recordWriter->upsertRecord($metafield, $owner, $definition->id);
                $this->recordWriter->syncValue($metafield, $item['value'] ?? null);
                $metafield->refresh()->loadMissing('definition');
                $syncedMetafields->push($metafield);
            }

            $ownerId = $owner->getKey();
            if (! is_string($ownerId) && ! is_int($ownerId)) {
                throw new TypeError('Committed metafield owner identity must be a string or integer.');
            }
            $metafieldIds = array_values($syncedMetafields->map(static fn (Metafield $metafield): string => $metafield->id)->all());
            $this->domainEvents->dispatch(new MetafieldsSynced($ownerType, $ownerId, $metafieldIds), (new Metafield)->getConnection());

            return $syncedMetafields->values();
        }, MetafieldConfiguration::positiveInteger('nvl-metafields.transactions.attempts', 3));

        return $synced;
    }

    /** Set a value by registered definition handle. */
    public function set(Model $owner, string $handle, mixed $value, Authenticatable|SystemActor $actor, ?string $locale = null, ?int $expectedRevision = null): Metafield
    {
        $definition = $this->definitionCatalog->findByHandle($handle);
        if (! $definition instanceof MetafieldDefinition) {
            throw new MetafieldDefinitionNotFoundException("Metafield definition not found: {$handle}");
        }
        $item = ['definitionId' => $definition->id];
        if ($definition->is_translatable) {
            if (! is_string($locale) || $locale === '') {
                throw new InvalidMetafieldMutationException("A locale is required when setting translatable metafield [{$handle}].");
            }
            $item['translations'] = [$locale => $value];
        } else {
            $item['value'] = $value;
        }
        if ($expectedRevision !== null) {
            $item['expected_revision'] = $expectedRevision;
        }

        return (new Metafield)->getConnection()->transaction(function () use ($owner, $item, $actor, $handle): Metafield {
            $metafield = $this->sync($owner, [$item], $actor)->first();
            if (! $metafield instanceof Metafield) {
                throw new RuntimeException("Metafield sync did not return a persisted row for [{$handle}].");
            }
            $this->domainEvents->dispatch(new MetafieldSet($metafield->id, $metafield->metafieldable_type, $metafield->metafieldable_id, $metafield->definition_id, $metafield->revision), $metafield->getConnection());

            return $metafield;
        });
    }

    /** Clear one assigned definition through the canonical sync pipeline. */
    public function delete(Model $owner, MetafieldDefinition|string $definition, int $expectedRevision, Authenticatable|SystemActor $actor): bool
    {
        $definitionId = $definition instanceof MetafieldDefinition ? $definition->getKey() : $definition;
        $this->sync($owner, [[
            'definitionId' => $definitionId,
            'clear' => true,
            'expected_revision' => $expectedRevision,
        ]], $actor);

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    private function validateItems(array $items): void
    {
        Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'list', 'min:1', 'max:'.MetafieldConfiguration::positiveInteger('nvl-metafields.limits.maximum_sync_items', 100)],
            'items.*' => ['required', 'array:definitionId,clear,value,translations,translation_mode,expected_revision'],
            'items.*.definitionId' => ['required', 'uuid', 'distinct'],
            'items.*.clear' => ['sometimes', 'boolean'],
            'items.*.translations' => ['sometimes', 'nullable', 'array'],
            'items.*.translation_mode' => ['sometimes', Rule::enum(TranslationSyncMode::class)],
            'items.*.expected_revision' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ])->validate();
    }
}
