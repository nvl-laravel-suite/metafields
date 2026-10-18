<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Ownership\Services\OwnershipCanonicalResolver;
use Nvl\Ownership\Services\OwnershipRecordReader;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Verifies historical defaults and values through full native reference ownership. @internal */
final readonly class MetafieldAdoptionReferences
{
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipCanonicalResolver $canonical, private OwnershipRecordReader $records, private MetafieldModelRegistry $models) {}

    public function verify(): void
    {
        $class = $this->models->definitionClass();
        $root = new $class;
        foreach ($root->getConnection()->table($root->getTable())->cursor() as $row) {
            $definition = $this->records->hydrate($root, $row);
            $this->references($definition, $definition->getAttribute('default_referenced_id'), $definition->getAttribute('default_value'), $this->canonical->fromPersisted($definition, $this->resources->get('metafields.definitions')));
        }
        $values = new ($this->resources->get('metafields.values')->model);
        foreach ($values->getConnection()->table($values->getTable())->cursor() as $row) {
            $value = $this->records->hydrate($values, $row);
            $definitionRow = $root->getConnection()->table($root->getTable())->where('id', $value->getAttribute('definition_id'))->first();
            if ($definitionRow === null) {
                throw new OwnershipViolation('Historical values require their exact definition.');
            }
            $this->references($this->records->hydrate($root, $definitionRow), $value->getAttribute('referenced_id'), $value->getAttribute('value'), $this->canonical->fromPersisted($value, $this->resources->get('metafields.values')));
        }
    }

    private function references(MetafieldDefinition $definition, mixed $id, mixed $value, ?OwnerReference $owner): void
    {
        $ids = match ($definition->type) {
            MetafieldTypeEnum::Reference => $id === null ? [] : [$id],MetafieldTypeEnum::ReferenceList => $definition->type->cast($value),default => []
        };
        if (! is_array($ids)) {
            throw new OwnershipViolation('Historical references require native scalar identifiers.');
        }
        $models = MetafieldReferenceModelRegistry::all();
        $class = $definition->referenced_model_type === null ? null : ($models[$definition->referenced_model_type] ?? null);
        foreach ($ids as $identifier) {
            if ((! is_int($identifier) && ! is_string($identifier)) || ! is_string($class)) {
                throw new OwnershipViolation('Historical reference declarations are incomplete.');
            }
            $target = new $class;
            $resource = $this->resources->forModel($target);
            if ($target->getConnection() !== $definition->getConnection()) {
                throw new OwnershipViolation('Historical references must retain their canonical source.');
            }
            $row = $target->getConnection()->table($target->getTable())->where($target->getKeyName(), $identifier)->first();
            if ($row === null) {
                throw new OwnershipViolation('A historical reference is missing.');
            }
            $record = $this->records->hydrate($target, $row);
            $key = $record->getKey();
            if ((! is_string($key) && ! is_int($key)) || (string) $key !== (string) $identifier) {
                throw new OwnershipViolation('Historical references require lossless native keys.');
            }
            if ($resource->ownership->strategy !== 'unscoped' && $this->canonical->fromPersisted($record, $resource) != $owner) {
                throw new OwnershipViolation('A historical reference belongs to a different native owner.');
            }
        }
    }
}
