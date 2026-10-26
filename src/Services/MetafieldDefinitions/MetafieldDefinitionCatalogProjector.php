<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Projects authorized canonical schema facts without issuing storage access.
 *
 * @internal
 *
 * @phpstan-import-type DefinitionTranslation from MetafieldDefinitionInput
 *
 * @phpstan-type CatalogDefinition array{namespace:string,key:string,type:MetafieldTypeEnum,title:string,description:?string,hint:?string,referencedModelType:?string,isTranslatable:bool,isRequired:bool,isFilterable:bool,validationRules:list<string>|null,jsonPropertySchema:list<array{key:string,type:string,isRequired:bool}>|null,defaultValue:mixed,displayOrder:int,revision:int,translations:array<string,DefinitionTranslation>}
 * @phpstan-type CatalogSnapshot array{grantId:string,grantRevision:int,sourceId:string,sourceRevision:int,sourceHash:string,definition:CatalogDefinition}
 */
final readonly class MetafieldDefinitionCatalogProjector
{
    public function __construct(private LocaleCatalog $locales) {}

    /**
     * @param  iterable<MetafieldDefinitionTranslation>  $rows
     * @return CatalogSnapshot
     */
    public function project(MetafieldDefinition $source, MetafieldDefinitionOwnerGrant $grant, iterable $rows): array
    {
        $translations = [];
        foreach ($rows as $translation) {
            $translations[$translation->locale] = [
                'title' => $translation->title,
                'description' => $translation->description,
                'hint' => $translation->hint,
                'defaultValue' => $translation->default_value === null ? null : $source->type->cast($translation->default_value),
                'properties' => $translation->properties,
            ];
        }
        if ($translations === []) {
            throw new OwnershipViolation('The granted platform definition has no localized title.');
        }
        $display = $this->displayTranslation($translations);
        $default = match ($source->type) {
            MetafieldTypeEnum::Reference => $source->default_referenced_id,
            default => $source->default_value === null ? null : $source->type->cast($source->default_value),
        };
        $payload = [
            'namespace' => $source->namespace,
            'key' => $source->key,
            'type' => $source->type,
            'title' => $display['title'],
            'description' => $display['description'],
            'hint' => $display['hint'],
            'referencedModelType' => $source->referenced_model_type,
            'isTranslatable' => $source->is_translatable,
            'isRequired' => $source->is_required,
            'isFilterable' => $source->is_filterable,
            'validationRules' => $source->validation_rules,
            'jsonPropertySchema' => $source->json_property_schema === null ? null : array_values($source->json_property_schema),
            'defaultValue' => $default,
            'displayOrder' => $source->display_order,
            'revision' => $source->revision,
            'translations' => $translations,
        ];
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));

        return [
            'grantId' => $grant->id,
            'grantRevision' => $grant->revision,
            'sourceId' => $source->id,
            'sourceRevision' => $source->revision,
            'sourceHash' => $hash,
            'definition' => $payload,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @return array{title: string, description: ?string, hint: ?string}
     */
    private function displayTranslation(array $translations): array
    {
        $chain = array_values(array_unique(array_filter([
            $this->locales->default(),
            ...$this->locales->fallbacks(),
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
        throw new OwnershipViolation('The granted platform definition title cannot be resolved.');
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
