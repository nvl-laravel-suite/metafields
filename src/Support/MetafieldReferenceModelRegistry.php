<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;

/**
 * Resolves and validates model classes that reference metafields may target.
 */
final class MetafieldReferenceModelRegistry
{
    /**
     * Return the configured stable reference aliases and their Eloquent models.
     *
     * Owner aliases are always valid reference targets. Additional models may
     * be registered under `metafields.reference_models`.
     *
     * @return array<string, class-string<Model>>
     */
    public static function all(): array
    {
        $models = [];
        $modelAliases = [];
        $owners = config('metafields.owners', []);

        if (! is_array($owners)) {
            throw new InvalidArgumentException('The [metafields.owners] configuration must be an array.');
        }

        foreach ($owners as $alias => $configuration) {
            if (! is_string($alias) || trim($alias) === '' || ! is_array($configuration)) {
                continue;
            }

            $modelClass = $configuration['model'] ?? null;

            if (is_string($modelClass) && self::isResolvableModelClass($modelClass)) {
                if (isset($modelAliases[$modelClass]) && $modelAliases[$modelClass] !== $alias) {
                    throw new InvalidArgumentException(
                        "The metafield reference model [{$modelClass}] is already registered as [{$modelAliases[$modelClass]}].",
                    );
                }

                /** @var class-string<Model> $modelClass */
                $models[$alias] = $modelClass;
                $modelAliases[$modelClass] = $alias;
            }
        }

        $configuredReferences = config('metafields.reference_models', []);

        if (! is_array($configuredReferences)) {
            throw new InvalidArgumentException('The [metafields.reference_models] configuration must be an array.');
        }

        foreach ($configuredReferences as $alias => $modelClass) {
            if (! is_string($alias) || trim($alias) === '') {
                throw new InvalidArgumentException(
                    'Every metafield reference target must use a non-empty stable string alias.',
                );
            }

            if (! is_string($modelClass) || ! self::isResolvableModelClass($modelClass)) {
                throw new InvalidArgumentException('Every metafield reference target must be an Eloquent model class.');
            }

            $normalizedAlias = trim($alias);

            if (isset($models[$normalizedAlias]) && $models[$normalizedAlias] !== $modelClass) {
                throw new InvalidArgumentException(
                    "Metafield reference alias [{$normalizedAlias}] is already registered for another model.",
                );
            }

            if (isset($modelAliases[$modelClass]) && $modelAliases[$modelClass] !== $normalizedAlias) {
                throw new InvalidArgumentException(
                    "The metafield reference model [{$modelClass}] is already registered as [{$modelAliases[$modelClass]}].",
                );
            }

            /** @var class-string<Model> $modelClass */
            $models[$normalizedAlias] = $modelClass;
            $modelAliases[$modelClass] = $normalizedAlias;
        }

        return $models;
    }

    /**
     * Return every unique Eloquent model allowed as a reference target.
     *
     * @return list<class-string<Model>>
     */
    public static function allowedModelClasses(): array
    {
        return array_values(array_unique(self::all()));
    }

    /**
     * Normalize an external reference model alias.
     */
    public static function normalizeModelClass(mixed $modelClass): ?string
    {
        if (! is_string($modelClass)) {
            return null;
        }

        $modelClass = trim($modelClass);

        return $modelClass === '' ? null : $modelClass;
    }

    /**
     * Determine whether a configured reference alias is allowed.
     */
    public static function isAllowedModelClass(mixed $modelClass): bool
    {
        return self::allowedModelClass($modelClass) !== null;
    }

    /**
     * Determine whether a referenced record exists without exposing it.
     */
    public static function referencedRecordExists(mixed $modelClass, mixed $id): bool
    {
        return app(MetafieldReferenceRecordResolver::class)->exists($modelClass, $id);
    }

    /**
     * Resolve an allowed referenced record by its stable alias and identifier.
     */
    public static function findReferencedRecord(mixed $modelClass, mixed $id): ?Model
    {
        return app(MetafieldReferenceRecordResolver::class)->resolve($modelClass, $id);
    }

    /**
     * @return class-string<Model>|null
     */
    private static function allowedModelClass(mixed $modelClass): ?string
    {
        $modelType = self::normalizeModelClass($modelClass);

        if ($modelType === null) {
            return null;
        }

        $models = self::all();
        $resolvedModelClass = $models[$modelType] ?? null;

        if (! is_string($resolvedModelClass) || ! self::isResolvableModelClass($resolvedModelClass)) {
            return null;
        }

        /** @var class-string<Model> $resolvedModelClass */
        return $resolvedModelClass;
    }

    private static function isResolvableModelClass(string $modelClass): bool
    {
        return class_exists($modelClass)
            && is_subclass_of($modelClass, Model::class);
    }

}
