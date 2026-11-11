<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use stdClass;

/**
 * Rechecks the concrete schema and reference graph during every native save.
 *
 * @internal
 */
final readonly class MetafieldGraphOwnership
{
    public function __construct(
        private MetafieldReferenceRecordResolver $references,
        private OwnershipBoundary $boundary,
        private OwnershipResourceRegistry $resources,
    ) {}

    /**
     * Compare every loaded locale row with its persisted identity in one admitted canonical query.
     *
     * @param  iterable<mixed>  $translations
     */
    public function assertLoadedTranslations(iterable $translations, string $resource, string $parentKey): void
    {
        $canonical = new ($this->resources->get($resource)->model);
        $identity = [$canonical->getKeyName(), 'owner_scope_key', $parentKey];
        $loaded = [];
        foreach ($translations as $translation) {
            $key = $translation instanceof Model ? $translation->getRawOriginal($canonical->getKeyName()) : null;
            if (! $translation instanceof Model || $translation::class !== $canonical::class || ! $translation->exists
                || $translation->getTable() !== $canonical->getTable() || $translation->getConnection() !== $canonical->getConnection()
                || (! is_string($key) && ! is_int($key)) || $translation->isDirty($identity)) {
                throw new OwnershipViolation('A loaded locale row differs from its canonical storage.');
            }
            $loaded[(string) $key] = $translation;
        }
        if ($loaded === []) {
            return;
        }
        $persisted = $this->boundary
            ->query($canonical->newQueryWithoutScopes()->whereKey(array_map(strval(...), array_keys($loaded))), $resource)
            ->toBase()
            ->get($identity)
            ->keyBy($canonical->getKeyName());
        foreach ($loaded as $key => $translation) {
            $row = $persisted->get($key);
            foreach ($identity as $field) {
                $stored = $row instanceof stdClass ? ($row->{$field} ?? null) : null;
                $held = $translation->getRawOriginal($field);
                if ((! is_string($stored) && ! is_int($stored)) || (! is_string($held) && ! is_int($held)) || (string) $stored !== (string) $held) {
                    throw new OwnershipViolation('A loaded locale row no longer represents its persisted ownership facts.');
                }
            }
        }
    }

    public function assertWrite(Model $model): void
    {
        if (! MetafieldOwnership::declared()) {
            return;
        }
        if ($model instanceof Metafield) {
            $definition = MetafieldModelRegistry::query()->whereKey($model->getAttribute('definition_id'))->lockForUpdate()->first();
            if ($definition === null) {
                throw new OwnershipViolation('A value requires its canonical definition in the current owner.');
            }
            $type = $definition->type;
            $alias = $definition->referenced_model_type;
            $id = $model->getAttribute('referenced_id');
            $value = $model->getAttribute('value');
        } elseif ($model instanceof MetafieldDefinition) {
            $type = $model->type;
            $alias = $model->referenced_model_type;
            $id = $model->getAttribute('default_referenced_id');
            $value = $model->getAttribute('default_value');
        } else {
            return;
        }
        $ids = $type === MetafieldTypeEnum::ReferenceList ? $type->cast($value) : ($type === MetafieldTypeEnum::Reference && $id !== null ? [$id] : []);
        if (is_array($ids)) {
            foreach ($ids as $reference) {
                if ($this->references->resolve($alias, $reference, lock: true) === null) {
                    throw new OwnershipViolation('Metafield references require their canonical current owner.');
                }
            }
        }
    }
}
