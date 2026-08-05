<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Translatable\Enums\TranslationSyncMode;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Persists owner metafield records and delegates locale-row synchronization to Translatable.
 */
final readonly class OwnerMetafieldRecordWriter
{
    /**
     * Create the owner metafield record writer.
     */
    public function __construct(
        private TranslationWriter $translations,
    ) {}

    /**
     * Clear an owner metafield and all localized values.
     */
    public function clear(?Metafield $metafield): void
    {
        if (! $metafield instanceof Metafield) {
            return;
        }

        $this->translations->replace($metafield, []);
        $metafield->delete();
    }

    /**
     * Synchronize localized values using an explicit patch or replace contract.
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

        $this->translations->sync($metafield, $storedTranslations, $mode);

        if (! $isInitialOrRestoredWrite) {
            $metafield->touch();
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
            $metafield->save();

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
