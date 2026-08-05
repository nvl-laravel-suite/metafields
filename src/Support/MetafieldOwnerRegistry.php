<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Metafields\Data\MetafieldOwner;
use Nvl\Metafields\Enums\MetafieldTypeEnum;

/**
 * Normalizes configured owner aliases and prevents ambiguous model registrations.
 */
final class MetafieldOwnerRegistry
{
    /**
     * Return every normalized owner registration keyed by stable alias.
     *
     * @return array<string, array{
     *     model: class-string<Model>,
     *     label: string,
     *     supported_types: array<int, string>,
     *     sections: array<int, string>,
     *     runtime_status: 'live'|'planned',
     *     show_route?: string,
     *     show_route_parameters?: array<string, string>
     * }>
     */
    public function all(): array
    {
        $owners = config('metafields.owners', []);

        if (! is_array($owners)) {
            throw new InvalidArgumentException('The [metafields.owners] configuration must be an array.');
        }

        $normalized = [];
        $registeredModels = [];
        $existingMorphMap = Relation::morphMap();

        foreach ($owners as $type => $configuration) {
            if (! is_string($type) || trim($type) === '') {
                throw new InvalidArgumentException('Every metafield owner must use a non-empty string alias.');
            }

            if (! is_array($configuration)) {
                throw new InvalidArgumentException("The metafield owner [{$type}] configuration must be an array.");
            }

            $normalized[$type] = $this->normalizeConfiguration($type, $configuration);
            $modelClass = $normalized[$type]['model'];

            $this->assertMorphMapCompatibility($type, $modelClass, $existingMorphMap);

            if (isset($registeredModels[$modelClass])) {
                throw new InvalidArgumentException(
                    "The metafield owner model [{$modelClass}] is already registered as [{$registeredModels[$modelClass]}].",
                );
            }

            foreach ($registeredModels as $registeredModel => $registeredType) {
                if (is_a($modelClass, $registeredModel, true)
                    || is_a($registeredModel, $modelClass, true)) {
                    throw new InvalidArgumentException(
                        "The metafield owner models [{$registeredModel}] and [{$modelClass}] "
                        ."cannot use separate aliases [{$registeredType}] and [{$type}] because their inheritance makes owner resolution ambiguous.",
                    );
                }
            }

            $registeredModels[$modelClass] = $type;
        }

        return $normalized;
    }

    /**
     * Reject aliases that would overwrite or duplicate the application's morph map.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, class-string<Model>>  $existingMorphMap
     */
    private function assertMorphMapCompatibility(
        string $type,
        string $modelClass,
        array $existingMorphMap,
    ): void {
        $mappedModel = $existingMorphMap[$type] ?? null;

        if (is_string($mappedModel) && $mappedModel !== $modelClass) {
            throw new InvalidArgumentException(
                "The metafield owner alias [{$type}] conflicts with the existing morph-map model [{$mappedModel}].",
            );
        }

        $mappedAlias = array_search($modelClass, $existingMorphMap, true);

        if (is_string($mappedAlias) && $mappedAlias !== $type) {
            throw new InvalidArgumentException(
                "The metafield owner model [{$modelClass}] already uses morph-map alias [{$mappedAlias}] instead of [{$type}].",
            );
        }
    }

    /**
     * Return the normalized configuration for one stable owner alias.
     *
     * @return array{
     *     model: class-string<Model>,
     *     label: string,
     *     supported_types: array<int, string>,
     *     sections: array<int, string>,
     *     runtime_status: 'live'|'planned',
     *     show_route?: string,
     *     show_route_parameters?: array<string, string>
     * }
     */
    public function configurationForType(string $type): array
    {
        $owner = $this->all()[$type] ?? null;

        if (! is_array($owner)) {
            throw new InvalidArgumentException("Unsupported metafield owner type [{$type}].");
        }

        return $owner;
    }

    /**
     * Build the public owner contract for a stable alias.
     */
    public function forType(string $type): MetafieldOwner
    {
        $owner = $this->configurationForType($type);

        return MetafieldOwner::from([
            'type' => $type,
            'label' => (string) trans($owner['label']),
            'supportedTypes' => $owner['supported_types'],
            'sections' => $owner['sections'],
            'runtimeStatus' => $owner['runtime_status'],
            'supportsRuntimeEditing' => $owner['runtime_status'] === 'live',
        ]);
    }

    /**
     * Resolve the unique stable alias for an owner model instance.
     */
    public function resolveOwnerType(Model $owner): string
    {
        foreach ($this->all() as $type => $configuration) {
            $modelClass = $configuration['model'];

            if ($owner instanceof $modelClass) {
                return $type;
            }
        }

        throw new InvalidArgumentException('Unsupported metafield owner model ['.$owner::class.'].');
    }

    /**
     * Determine whether an owner registration supports a metafield type.
     */
    public function supports(string $ownerType, MetafieldTypeEnum $fieldType): bool
    {
        return in_array($fieldType->value, $this->forType($ownerType)->supportedTypes, true);
    }

    /**
     * Determine whether an owner registration is available to runtime APIs.
     */
    public function supportsRuntimeEditing(string $ownerType): bool
    {
        return $this->forType($ownerType)->supportsRuntimeEditing;
    }

    /**
     * @param  array<mixed, mixed>  $configuration
     * @return array{
     *     model: class-string<Model>,
     *     label: string,
     *     supported_types: list<string>,
     *     sections: list<string>,
     *     runtime_status: 'live'|'planned',
     *     show_route?: string,
     *     show_route_parameters?: array<string, string>
     * }
     */
    private function normalizeConfiguration(string $type, array $configuration): array
    {
        $modelClass = $configuration['model'] ?? null;

        if (! is_string($modelClass)
            || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(
                "The metafield owner [{$type}] must configure an Eloquent model class.",
            );
        }

        $label = $configuration['label'] ?? $type;

        if (! is_string($label) || trim($label) === '') {
            throw new InvalidArgumentException("The metafield owner [{$type}] label must be a non-empty string.");
        }

        $supportedTypes = $configuration['supported_types']
            ?? array_map(
                static fn (MetafieldTypeEnum $fieldType): string => $fieldType->value,
                MetafieldTypeEnum::cases(),
            );

        if (! is_array($supportedTypes)
            || ! array_is_list($supportedTypes)
            || $supportedTypes === []
            || collect($supportedTypes)->contains(
                static fn (mixed $fieldType): bool => ! is_string($fieldType)
                    || MetafieldTypeEnum::tryFrom($fieldType) === null,
            )) {
            throw new InvalidArgumentException(
                "The metafield owner [{$type}] supported_types must be a non-empty list of supported type values.",
            );
        }

        $sections = $configuration['sections'] ?? ['general'];

        if (! is_array($sections)
            || ! array_is_list($sections)
            || $sections === []
            || collect($sections)->contains(
                static fn (mixed $section): bool => ! is_string($section) || trim($section) === '',
            )) {
            throw new InvalidArgumentException(
                "The metafield owner [{$type}] sections must be a non-empty list of strings.",
            );
        }

        $runtimeStatus = $configuration['runtime_status'] ?? 'live';

        if (! in_array($runtimeStatus, ['live', 'planned'], true)) {
            throw new InvalidArgumentException(
                "The metafield owner [{$type}] runtime_status must be [live] or [planned].",
            );
        }

        /** @var class-string<Model> $modelClass */
        /** @var list<string> $supportedTypes */
        /** @var list<string> $sections */
        /** @var 'live'|'planned' $runtimeStatus */
        $normalized = [
            'model' => $modelClass,
            'label' => $label,
            'supported_types' => array_values(array_unique($supportedTypes)),
            'sections' => array_values(array_unique($sections)),
            'runtime_status' => $runtimeStatus,
        ];

        $showRoute = $configuration['show_route'] ?? null;

        if ($showRoute !== null) {
            if (! is_string($showRoute) || trim($showRoute) === '') {
                throw new InvalidArgumentException(
                    "The metafield owner [{$type}] show_route must be a non-empty string.",
                );
            }

            $normalized['show_route'] = $showRoute;
        }

        $showRouteParameters = $configuration['show_route_parameters'] ?? null;

        if ($showRouteParameters !== null) {
            if (! is_array($showRouteParameters)
                || collect($showRouteParameters)->contains(
                    static fn (mixed $value, mixed $key): bool => ! is_string($key)
                        || ! is_string($value)
                        || trim($key) === ''
                        || trim($value) === '',
                )) {
                throw new InvalidArgumentException(
                    "The metafield owner [{$type}] show_route_parameters must map route keys to model attributes.",
                );
            }

            /** @var array<string, string> $showRouteParameters */
            $normalized['show_route_parameters'] = $showRouteParameters;
        }

        return $normalized;
    }
}
