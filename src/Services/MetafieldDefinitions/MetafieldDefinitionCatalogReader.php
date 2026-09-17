<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Contracts\Config\Repository;
use Nvl\Metafields\Data\MetafieldDefinitionCatalogSnapshot;
use Nvl\Metafields\Data\MetafieldDefinitionPayload;
use Nvl\Metafields\Data\MetafieldJsonProperty;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Spatie\LaravelData\DataCollection;

/** Reads one explicitly granted platform definition into a scalar snapshot. */
final readonly class MetafieldDefinitionCatalogReader
{
    /** Create the narrow authorized reader. */
    public function __construct(private TenantContext $context, private Repository $configuration) {}

    /** Return the immutable authorized source snapshot. */
    public function find(string $grantId): MetafieldDefinitionCatalogSnapshot
    {
        $tenant = $this->context->requireTenant();
        $grant = MetafieldDefinitionTenantGrant::query()->whereKey($grantId)->first();
        if (! $grant instanceof MetafieldDefinitionTenantGrant || $grant->tenant_id !== $tenant->value
            || ! $grant->enabled || $grant->revoked_at !== null) {
            throw new TenantBoundaryViolation('The Metafield catalog grant is unavailable.');
        }
        $source = MetafieldDefinition::withoutGlobalScope('tenant')
            ->whereKey($grant->definition_id)
            ->whereNull('tenant_id')
            ->where('ownership_key', 'platform')
            ->whereNotNull('active_handle')
            ->first();
        if (! $source instanceof MetafieldDefinition || $source->revision !== $grant->source_revision) {
            throw new TenantBoundaryViolation('The granted platform definition revision is unavailable.');
        }

        $translations = [];
        foreach (MetafieldDefinitionTranslation::withoutGlobalScope('tenant')
            ->where('metafield_definition_id', $source->id)
            ->where('ownership_key', 'platform')
            ->orderBy('locale')
            ->get() as $translation) {
            $translations[$translation->locale] = [
                'title' => $translation->title,
                'description' => $translation->description,
                'hint' => $translation->hint,
                'defaultValue' => $translation->default_value === null ? null : $source->type->cast($translation->default_value),
                'properties' => $translation->properties,
            ];
        }
        if ($translations === []) {
            throw new TenantBoundaryViolation('The granted platform definition has no localized title.');
        }
        $display = $this->displayTranslation($translations);
        $default = match ($source->type) {
            MetafieldTypeEnum::Reference => $source->default_referenced_id,
            default => $source->default_value === null ? null : $source->type->cast($source->default_value),
        };
        $payload = new MetafieldDefinitionPayload(
            namespace: $source->namespace,
            key: $source->key,
            type: $source->type,
            title: $display['title'],
            description: $display['description'],
            hint: $display['hint'],
            referencedModelType: $source->referenced_model_type,
            isTranslatable: $source->is_translatable,
            isRequired: $source->is_required,
            isFilterable: $source->is_filterable,
            validationRules: $source->validation_rules,
            jsonPropertySchema: is_array($source->json_property_schema)
                ? MetafieldJsonProperty::collect($source->json_property_schema, DataCollection::class)
                : null,
            defaultValue: $default,
            displayOrder: $source->display_order,
            revision: $source->revision,
            translations: $translations,
        );
        $hash = hash('sha256', json_encode($this->canonical($payload->toArray()), JSON_THROW_ON_ERROR));

        return new MetafieldDefinitionCatalogSnapshot(
            grantId: $grant->id,
            grantRevision: $grant->revision,
            sourceId: $source->id,
            sourceRevision: $source->revision,
            sourceHash: $hash,
            definition: $payload,
        );
    }

    /** @param array<string, array<string, mixed>> $translations @return array{title: string, description: ?string, hint: ?string} */
    private function displayTranslation(array $translations): array
    {
        $chain = array_values(array_unique(array_filter([
            $this->configuration->get('translatable.default_locale'),
            ...(array) $this->configuration->get('translatable.fallback_locales', []),
            ...array_keys($translations),
        ], is_string(...))));
        foreach ($chain as $locale) {
            $translation = $translations[$locale] ?? null;
            if (is_array($translation) && is_string($translation['title'] ?? null) && $translation['title'] !== '') {
                return [
                    'title' => $translation['title'],
                    'description' => is_string($translation['description'] ?? null) ? $translation['description'] : null,
                    'hint' => is_string($translation['hint'] ?? null) ? $translation['hint'] : null,
                ];
            }
        }
        throw new TenantBoundaryViolation('The granted platform definition title cannot be resolved.');
    }

    /** Recursively sort associative maps before hashing. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
