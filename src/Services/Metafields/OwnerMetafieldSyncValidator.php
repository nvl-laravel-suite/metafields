<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Data\SyncOwnerMetafieldValuePayload;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Translatable\Services\LocaleRegistry;
use Spatie\LaravelData\Optional;

/**
 * Enforces owner assignment, completeness, locale, and typed-value invariants.
 */
final readonly class OwnerMetafieldSyncValidator
{
    public function __construct(
        private MetafieldOwnerRegistry $ownerRegistry,
        private MetafieldValueValidator $metafieldValueValidator,
        private LocaleRegistry $locales,
    ) {}

    /**
     * Require a non-empty set of unique definition identifiers.
     *
     * @param  list<string>  $definitionIds
     */
    public function ensureDefinitionIdsAreUnique(array $definitionIds): void
    {
        if ($definitionIds !== [] && count($definitionIds) === count(array_unique($definitionIds))) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => [
                $definitionIds === []
                    ? trans('metafields::owner-metafields/validation.custom.items.required')
                    : trans('metafields::owner-metafields/validation.custom.items.distinct'),
            ],
        ]);
    }

    /**
     * Require every submitted definition to have an active owner assignment.
     *
     * @param  list<string>  $definitionIds
     * @param  Collection<string, MetafieldDefinitionAssignment>  $assignments
     */
    public function ensureAssignmentsPresent(array $definitionIds, Collection $assignments): void
    {
        if (count($definitionIds) === $assignments->count()) {
            return;
        }

        $missingDefinitionIds = array_values(array_diff($definitionIds, $assignments->keys()->all()));

        throw ValidationException::withMessages([
            'items' => [
                trans('metafields::owner-metafields/validation.custom.items.assigned', [
                    'definitions' => implode(', ', $missingDefinitionIds),
                ]),
            ],
        ]);
    }

    /**
     * Require submitted sections to satisfy every effective required assignment.
     *
     * @param  list<string>  $definitionIds
     * @param  Collection<string, MetafieldDefinitionAssignment>  $assignments
     * @param  Collection<string, Metafield>  $currentRecords
     */
    public function ensureRequiredAssignmentsPresent(
        array $definitionIds,
        Collection $assignments,
        Collection $currentRecords,
    ): void {
        /** @var list<string> $missingRequiredDefinitions */
        $missingRequiredDefinitions = $assignments
            ->filter(static function (MetafieldDefinitionAssignment $assignment): bool {
                $definition = $assignment->definition;

                if (! $definition instanceof MetafieldDefinition) {
                    return false;
                }

                return $assignment->is_required || $definition->is_required;
            })
            ->reject(
                static function (MetafieldDefinitionAssignment $assignment) use (
                    $definitionIds,
                    $currentRecords,
                ): bool {
                    if (in_array($assignment->definition_id, $definitionIds, true)) {
                        return true;
                    }

                    $currentRecord = $currentRecords->get($assignment->definition_id);

                    if ($currentRecord instanceof Metafield
                        && self::hasStoredValue($assignment, $currentRecord)) {
                        return true;
                    }

                    return $assignment->definition instanceof MetafieldDefinition
                        && $assignment->definition->hasDefaultValue();
                },
            )
            ->map(static function (MetafieldDefinitionAssignment $assignment): string {
                $definition = $assignment->definition;

                return $definition instanceof MetafieldDefinition
                    ? $definition->handle
                    : $assignment->definition_id;
            })
            ->values()
            ->all();

        if ($missingRequiredDefinitions === []) {
            return;
        }

        throw ValidationException::withMessages([
            'items' => [
                trans('metafields::owner-metafields/validation.custom.items.missing_required', [
                    'definitions' => implode(', ', $missingRequiredDefinitions),
                ]),
            ],
        ]);
    }

    /**
     * Require an active definition supported by the registered owner type.
     */
    public function ensureDefinitionCanSync(
        MetafieldDefinition $definition,
        string $ownerType,
        int $index,
    ): void {
        if (! $this->ownerRegistry->supports($ownerType, $definition->type)) {
            throw ValidationException::withMessages([
                "items.{$index}.definitionId" => [
                    trans('metafields::owner-metafields/validation.custom.definitionId.unsupported_type'),
                ],
            ]);
        }

        if ($definition->is_translatable && ! $definition->type->supportsTranslations()) {
            throw ValidationException::withMessages([
                "items.{$index}.translations" => [
                    trans('metafields::owner-metafields/validation.custom.translations.reference_not_supported'),
                ],
            ]);
        }

        $invalidCustomRules = $this->metafieldValueValidator->invalidCustomRules($definition);

        if ($invalidCustomRules !== []) {
            throw ValidationException::withMessages([
                "items.{$index}.definitionId" => [
                    trans('metafields::owner-metafields/validation.custom.definitionId.invalid_definition_rules', [
                        'rules' => implode(', ', $invalidCustomRules),
                    ]),
                ],
            ]);
        }
    }

    /**
     * Prevent clearing an effective required assignment without a default.
     */
    public function ensureRequiredAssignmentCanClear(
        MetafieldDefinitionAssignment $assignment,
        MetafieldDefinition $definition,
        bool $shouldClear,
        int $index,
    ): void {
        if (! $shouldClear) {
            return;
        }

        if (! $assignment->is_required && ! $definition->is_required) {
            return;
        }

        if ($definition->hasDefaultValue()) {
            return;
        }

        throw ValidationException::withMessages([
            "items.{$index}.clear" => [
                trans('metafields::owner-metafields/validation.custom.clear.required_assignment'),
            ],
        ]);
    }

    /**
     * Require and compare the expected revision whenever an active value is mutated.
     */
    public function ensureExpectedRevision(
        ?Metafield $metafield,
        int|Optional|null $expectedRevision,
        string $definitionId,
        int $index,
    ): void {
        if ($metafield instanceof Metafield && ! is_int($expectedRevision)) {
            throw ValidationException::withMessages([
                "items.{$index}.expectedRevision" => [
                    trans('metafields::owner-metafields/validation.custom.expectedRevision.required'),
                ],
            ]);
        }

        if (! is_int($expectedRevision)) {
            return;
        }

        if (! $metafield instanceof Metafield || $metafield->revision !== $expectedRevision) {
            throw StaleMetafieldVersionException::forResource(
                'metafield value',
                $definitionId,
            );
        }
    }

    /**
     * Validate one locale-neutral value against its effective definition contract.
     */
    public function validateNonTranslatableValue(
        SyncOwnerMetafieldValuePayload $item,
        MetafieldDefinition $definition,
        MetafieldDefinitionAssignment $assignment,
        Model $owner,
        int $index,
    ): void {
        if (! ($item->translations instanceof Optional) && $item->translations !== null) {
            throw ValidationException::withMessages([
                "items.{$index}.translations" => [
                    trans('metafields::owner-metafields/validation.custom.translations.not_allowed'),
                ],
            ]);
        }

        if (! $this->metafieldValueValidator->passes(
            definition: $definition,
            owner: $owner,
            value: $item->value,
            required: $assignment->is_required || $definition->is_required,
        )) {
            throw ValidationException::withMessages([
                "items.{$index}.value" => [
                    trans('metafields::owner-metafields/validation.custom.value.invalid'),
                ],
            ]);
        }
    }

    /**
     * Validate supplied localized values and locale keys before persistence.
     */
    public function validateTranslations(
        SyncOwnerMetafieldValuePayload $item,
        MetafieldDefinition $definition,
        MetafieldDefinitionAssignment $assignment,
        Model $owner,
        int $index,
    ): void {
        if ($item->translations instanceof Optional || $item->translations === null || $item->translations === []) {
            throw ValidationException::withMessages([
                "items.{$index}.translations" => [
                    trans('metafields::owner-metafields/validation.custom.translations.required'),
                ],
            ]);
        }

        if (! ($item->value instanceof Optional) && $item->value !== null) {
            throw ValidationException::withMessages([
                "items.{$index}.value" => [
                    trans('metafields::owner-metafields/validation.custom.value.translatable_forbidden'),
                ],
            ]);
        }

        foreach ($item->translations as $locale => $value) {
            if ($locale === '' || ! $this->locales->supports($locale)) {
                throw ValidationException::withMessages([
                    "items.{$index}.translations" => [
                        trans('metafields::owner-metafields/validation.custom.translations.locale_key'),
                    ],
                ]);
            }

            if ($value === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.translations.{$locale}" => [
                        trans(
                            'metafields::owner-metafields/validation.custom.translations.null_not_allowed',
                        ),
                    ],
                ]);
            }

            if (! $this->metafieldValueValidator->passes(
                definition: $definition,
                owner: $owner,
                value: $value,
                required: $assignment->is_required || $definition->is_required,
            )) {
                throw ValidationException::withMessages([
                    "items.{$index}.translations.{$locale}" => [
                        trans('metafields::owner-metafields/validation.custom.translations.invalid'),
                    ],
                ]);
            }
        }
    }

    /**
     * Determine whether a persisted row contains a semantically present value.
     */
    private static function hasStoredValue(
        MetafieldDefinitionAssignment $assignment,
        Metafield $metafield,
    ): bool {
        $definition = $assignment->definition;

        if (! $definition instanceof MetafieldDefinition) {
            return false;
        }

        if ($definition->is_translatable) {
            return $metafield->translations->contains(
                static fn (mixed $translation): bool => $translation instanceof MetafieldTranslation
                    && self::isPresent($translation->value),
            );
        }

        if ($definition->type === MetafieldTypeEnum::Reference) {
            return self::isPresent($metafield->referenced_id);
        }

        return self::isPresent($definition->type->cast($metafield->value));
    }

    /**
     * Determine whether a typed value satisfies required-field presence semantics.
     */
    private static function isPresent(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return ! is_countable($value) || count($value) > 0;
    }
}
