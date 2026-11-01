<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Exceptions\InvalidMetafieldMutationException;
use Nvl\Metafields\Exceptions\MetafieldDefinitionNotFoundException;
use Nvl\Metafields\Exceptions\MetafieldIntegrityException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldAssignmentCatalog;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordFinder;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldRecordWriter;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldSyncValidator;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Events\DomainEventDispatcher;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Translatable\Enums\TranslationSyncMode;
use PDO;
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
        private MetafieldAuthorization $authorization,
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

        return $this->syncAuthorized($owner, $items, $actor);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return Collection<int, Metafield>
     */
    private function syncAuthorized(Model $owner, array $items, Authenticatable|SystemActor $actor): Collection
    {
        $this->validateItems($items);
        /** @var list<array{definitionId:string,clear?:bool|0|1|'0'|'1',value?:mixed,translations?:array<string,mixed>|null,translation_mode?:TranslationSyncMode|string,expected_revision?:int|float|numeric-string|bool|null}> $validatedItems */
        $validatedItems = $items;
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
            $actor,
        ): Collection {
            $owner = $this->ownerResolver->canonical($owner, lock: true);
            $ownerType = $this->ownerRegistry->resolveOwnerType($owner);
            $this->authorization->authorizeOwner($actor, MetafieldAbility::MutateOwner, $owner);
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
                    $this->authorization->authorizeOwner($actor, MetafieldAbility::DeleteOwnerValue, $owner, $definition);
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
        $actor = $this->actors->authorize($actor, 'write');
        $owner = $this->ownerResolver->canonical($owner);
        $this->ownerRegistry->resolveOwnerType($owner);
        $this->authorization->authorizeOwner($actor, MetafieldAbility::MutateOwner, $owner);
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
            $metafield = $this->syncAuthorized($owner, [$item], $actor)->first();
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

    /**
     * Permanently purge this owner's active and cleared values, including locale rows.
     *
     * Requires the host's existing transaction on the exact owner/value source and
     * dedicated metafields.purge authority. The host must permanently delete the
     * owner in that same transaction; normal soft deletion retains its values.
     * Definitions and other owners remain intact. Hydration is bounded to 100
     * values at a time, and the synchronization fact follows the outer commit.
     *
     * @return int Number of physically removed value records
     */
    public function purgeBeforeOwnerDeletion(Model $owner, Authenticatable|SystemActor $actor): int
    {
        $connection = (new Metafield)->getConnection();
        $pdo = $connection->getRawPdo();
        $hostTransactionLevel = $connection->transactionLevel();
        $actor = $this->actors->authorize($actor, 'purge');
        if (! $pdo instanceof PDO || $hostTransactionLevel === 0 || ! $pdo->inTransaction()) {
            throw new InvalidMetafieldMutationException('Metafield lifecycle cleanup requires an existing host deletion transaction.');
        }
        $this->assertPurgeSource($connection, $pdo, $hostTransactionLevel);
        $this->ownerRegistry->resolveOwnerType($owner);
        if ($owner->getConnection() !== $connection
            || MetafieldModelRegistry::newInstance()->getConnection() !== $connection
            || (new MetafieldTranslation)->getConnection() !== $connection) {
            throw new OwnershipViolation('Metafield lifecycle cleanup requires the same actual owner/value/definition/locale connection.');
        }

        $event = null;
        $deleted = $connection->transaction(function () use ($owner, $actor, $connection, $pdo, $hostTransactionLevel, &$event): int {
            $assertSource = function () use ($connection, $pdo, $hostTransactionLevel, &$owner): void {
                $this->assertPurgeSource($connection, $pdo, $hostTransactionLevel + 1);
                if ($owner->getConnection() !== $connection) {
                    throw new OwnershipViolation('The Metafield deletion owner source changed.');
                }
            };
            $assertSource();
            $owner = $this->ownerResolver->forDeletion($owner);
            $assertSource();
            $ownerType = $this->ownerRegistry->resolveOwnerType($owner);
            $values = Metafield::withTrashed()->useWritePdo();
            MetafieldOwnerPredicate::equal($values, 'metafieldable_type', $owner->getMorphClass());
            MetafieldOwnerPredicate::equal($values, 'metafieldable_id', ModelIdentifier::required($owner));
            $deleted = 0;

            while (true) {
                $assertSource();
                $actor = $this->actors->authorize($actor, 'purge');
                $assertSource();
                $owner = $this->ownerResolver->forDeletion($owner);
                $assertSource();
                $this->authorization->authorizeOwner($actor, MetafieldAbility::MutateOwner, $owner);
                $assertSource();
                $this->authorization->authorizeOwner($actor, MetafieldAbility::PurgeOwnerValues, $owner);
                $assertSource();
                $candidates = (clone $values)->orderBy('definition_id')->orderBy('id')->limit(100)->get(['id', 'definition_id']);
                $assertSource();
                if ($candidates->isEmpty()) {
                    break;
                }
                $definitionIds = $candidates->map(static fn (Metafield $value): string => $value->definition_id)->unique()->values()->all();
                $definitions = MetafieldModelRegistry::query()->withTrashed()->withoutEagerLoads()->whereKey($definitionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $assertSource();
                $records = (clone $values)->whereKey($candidates->modelKeys())->orderBy('id')->lockForUpdate()->get();
                $assertSource();
                foreach ($records as $record) {
                    $definition = $definitions->get($record->definition_id);
                    if (! $definition instanceof MetafieldDefinition) {
                        throw new MetafieldIntegrityException('Metafield lifecycle cleanup requires every retained definition.');
                    }
                    $this->authorization->authorizeOwner($actor, MetafieldAbility::DeleteOwnerValue, $owner, $definition);
                    $assertSource();
                }
                foreach ($records as $record) {
                    $assertSource();
                    $this->recordWriter->purge($record, function (Model $model) use ($assertSource, $connection): void {
                        $assertSource();
                        if ($model->getConnection() !== $connection) {
                            throw new OwnershipViolation('The Metafield deletion record source changed.');
                        }
                    });
                    $assertSource();
                    $deleted++;
                }
            }
            if ($deleted > 0) {
                $ownerId = $owner->getKey();
                if (! is_string($ownerId) && ! is_int($ownerId)) {
                    throw new TypeError('Committed metafield owner identity must be a string or integer.');
                }
                $event = new MetafieldsSynced($ownerType, $ownerId, []);
            }

            return $deleted;
        }, MetafieldConfiguration::positiveInteger('nvl-metafields.transactions.attempts', 3));
        $this->assertPurgeSource($connection, $pdo, $hostTransactionLevel);
        if ($connection->transactionLevel() !== $hostTransactionLevel) {
            throw new OwnershipViolation('The Metafield deletion transaction boundary changed.');
        }
        if ($event instanceof MetafieldsSynced) {
            $this->domainEvents->dispatch($event, $connection);
        }

        return $deleted;
    }

    /** Read the actual mutable session boundary again after host callbacks.
     * @phpstan-impure
     */
    private function assertPurgeSource(Connection $connection, PDO $pdo, int $transactionLevel): void
    {
        if ($connection->getRawPdo() !== $pdo || $connection->transactionLevel() < $transactionLevel || ! $pdo->inTransaction()) {
            throw new OwnershipViolation('The Metafield deletion source changed.');
        }
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

        foreach ($items as $index => $item) {
            $clear = in_array($item['clear'] ?? false, [true, 1, '1'], true);
            $hasValue = array_key_exists('value', $item);
            $hasTranslations = array_key_exists('translations', $item);
            if (($clear && ($hasValue || $hasTranslations || array_key_exists('translation_mode', $item)))
                || (! $clear && $hasValue === $hasTranslations)
                || (! $hasTranslations && array_key_exists('translation_mode', $item))) {
                throw ValidationException::withMessages([
                    "items.{$index}" => ['Provide exactly one of value, translations, or clear; translation_mode requires translations.'],
                ]);
            }
        }
    }
}
