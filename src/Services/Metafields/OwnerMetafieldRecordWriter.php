<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\RelatedTranslationStore;
use RuntimeException;

/**
 * Persists owner metafield records and delegates locale-row synchronization to Translatable.
 *
 * @internal
 */
final readonly class OwnerMetafieldRecordWriter
{
    /**
     * Create the owner metafield record writer.
     */
    public function __construct(
        private RelatedTranslationStore $translations,
        private OwnershipWriter $ownershipWriter,
    ) {}

    /**
     * Clear an owner metafield and all localized values.
     */
    public function clear(?Metafield $metafield): void
    {
        if (! $metafield instanceof Metafield) {
            return;
        }

        $this->translations->deleteExcept($metafield, $metafield->translationDefinition(), []);
        $metafield->delete();
    }

    /** Permanently remove localized rows and their locked parent within the owning lifecycle transaction.
     * @param  Closure(Model): void  $assertSource
     */
    public function purge(Metafield $metafield, Closure $assertSource): void
    {
        do {
            $assertSource($metafield);
            $translations = MetafieldTranslation::query()->withTrashed()->useWritePdo()->where('metafield_id', $metafield->id)->orderBy('id')->limit(100)->lockForUpdate()->get();
            $assertSource($metafield);
            foreach ($translations as $translation) {
                if ($translation->deleteForLifecycle($assertSource) !== true) {
                    throw new RuntimeException('Metafield locale lifecycle cleanup was interrupted.');
                }
                $assertSource($translation);
            }
        } while ($translations->isNotEmpty());
        $assertSource($metafield);
        if ($metafield->forceDeleteForLifecycle($assertSource) !== true) {
            throw new RuntimeException('Metafield lifecycle cleanup was interrupted.');
        }
        $assertSource($metafield);
    }

    /**
     * Synchronize localized values and consume one revision for each accepted existing-record write.
     *
     * @param  array<string, mixed>  $translations
     */
    public function syncTranslations(
        Metafield $metafield,
        array $translations,
        MetafieldDefinition $definition,
        TranslationSyncMode $mode = TranslationSyncMode::Patch,
    ): void {
        $isInitialOrRestoredWrite = $this->isInitialOrRestoredWrite($metafield);
        /** @var array<string, array{value: mixed}> $storedTranslations */
        $storedTranslations = collect($translations)
            ->mapWithKeys(function (mixed $value, string $locale) use ($definition): array {
                return [
                    $locale => [
                        'value' => $this->serializeStoredValue(
                            $definition->type->storeCast($value),
                        ),
                    ],
                ];
            })
            ->all();

        $translationDefinition = $metafield->translationDefinition();
        foreach ($storedTranslations as $locale => $attributes) {
            $this->translations->upsert($metafield, $translationDefinition, $locale, $attributes);
        }
        if ($mode === TranslationSyncMode::Replace) {
            $this->translations->deleteExcept($metafield, $translationDefinition, array_keys($storedTranslations));
        }

        if (! $isInitialOrRestoredWrite) {
            $metafield->revision++;
            $metafield->save();
        }
    }

    /**
     * Persist one locale-neutral value or reference.
     */
    public function syncValue(Metafield $metafield, mixed $value): void
    {
        $isInitialOrRestoredWrite = $this->isInitialOrRestoredWrite($metafield);
        $metafield->loadMissing('definition');
        $definition = $metafield->definition;

        if ($definition->type === MetafieldTypeEnum::Reference) {
            $metafield->referenced_id = $value instanceof Model
                ? ModelIdentifier::required($value)
                : (is_string($value) ? $value : null);
            $metafield->value = null;
        } else {
            $metafield->value = $this->serializeStoredValue(
                $definition->type->storeCast($value),
            );
            $metafield->referenced_id = null;
        }

        if ($isInitialOrRestoredWrite) {
            $metafield->saveQuietly();

            return;
        }

        $metafield->save();
    }

    /**
     * Create or restore the owner record for one definition.
     */
    public function upsertRecord(
        ?Metafield $metafield,
        Model $owner,
        string $definitionId,
    ): Metafield {
        if ($metafield === null) {
            $metafield = new Metafield([
                'definition_id' => $definitionId,
                'metafieldable_id' => ModelIdentifier::required($owner),
                'metafieldable_type' => $owner->getMorphClass(),
            ]);
            $this->ownershipWriter->create(Metafield::class, static function (array $ownership) use ($metafield): Metafield {
                $metafield->forceFill($ownership)->save();

                return $metafield;
            });

            return $metafield;
        }

        if ($metafield->trashed()) {
            $metafield->restore();
        }

        return $metafield;
    }

    /**
     * Serialize a cast value for the text persistence column.
     */
    private function serializeStoredValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Determine whether the containing logical mutation already established its revision.
     */
    private function isInitialOrRestoredWrite(Metafield $metafield): bool
    {
        return $metafield->wasRecentlyCreated || $metafield->wasChanged('deleted_at');
    }
}
