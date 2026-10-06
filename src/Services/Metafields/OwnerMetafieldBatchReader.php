<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Data\OwnerMetafieldFields;
use Nvl\Metafields\Data\OwnersMetafields;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Exceptions\MetafieldIntegrityException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Relations\ExactOwnerTextColumn;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Owners\OwnerBatch;
use Nvl\Support\Owners\OwnerIdentity;
use Nvl\Support\Owners\OwnerResultMap;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Grouped owner admission and bounded SQL-visible assigned field projection. */
final readonly class OwnerMetafieldBatchReader
{
    /** Retain request-scoped authorization, identity and locale dependencies. */
    public function __construct(
        private MetafieldOwnerRegistry $registry,
        private MetafieldBatchAuthorization $policy,
        private MetafieldBatchReferenceLoader $references,
        private Repository $configuration,
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
        private LocaleCatalog $locales,
    ) {}

    /** @param list<Model> $owners */
    public function read(array $owners, ?string $locale = null): OwnersMetafields
    {
        $input = OwnerBatch::fromModels($owners);
        if ($input->isEmpty()) {
            return $this->result($input, []);
        }
        $locale = $this->locales->assertSupported($locale ?? (new MetafieldDefinition)->getCurrentLocale());
        $chain = (new MetafieldDefinition)->translationDefinition()->localeChain($locale, $this->locales->supported());
        if (count($chain) > 10) {
            throw new MetafieldBatchReadException('Metafield batch reads allow at most ten requested and fallback locales.');
        }
        $batch = $this->admit($input);
        $this->policy->authorizeOwners($batch);
        $aliases = [];
        foreach ($batch->owners() as $owner) {
            $aliases[] = $this->registry->resolveOwnerType($owner);
        }
        $query = MetafieldDefinitionAssignment::query();
        MetafieldOwnerPredicate::identifiers($query, 'owner_type', array_values(array_unique($aliases)));
        $query
            ->where('is_active', true)
            ->whereHas('definition', static fn (Builder $definition) => $definition->whereNotNull('active_handle'))
            ->where(fn (Builder $nested) => $this->policy->scopeAssignments($nested, $batch));
        $counts = (clone $query)->selectRaw('owner_type, COUNT(*) AS aggregate')->groupBy('owner_type', new ExactOwnerTextColumn('owner_type', $query->getModel()->getConnection()->getDriverName()))->toBase()->get();
        foreach ($counts as $count) {
            $aggregate = $count->aggregate;
            if ((! is_int($aggregate) && ! is_string($aggregate)) || ! is_numeric($aggregate) || (int) $aggregate > 100) {
                throw new MetafieldBatchReadException('Metafield batches allow at most 100 active definitions per owner type.');
            }
        }
        $assignments = $query->orderBy('display_order')->orderBy('id')->limit(10001)->get();
        if ($assignments->count() > 10000 || $assignments->groupBy('owner_type')->contains(static fn (Collection $group): bool => $group->count() > 100)) {
            throw new MetafieldBatchReadException('Metafield batches allow at most 100 active definitions per owner type.');
        }
        $assignments->load('definition');
        $selected = [];
        $definitions = [];
        foreach ($batch->owners() as $index => $owner) {
            $identity = $batch->identities()[$index];
            foreach ($assignments as $assignment) {
                $definition = $assignment->definition;
                if ($assignment->owner_type === $aliases[$index] && $definition instanceof MetafieldDefinition
                    && $this->policy->allowsDefinition($owner, $definition)) {
                    $selected[$identity->type][$identity->id][$definition->id] = $assignment;
                    $definitions[$definition->id] = $definition;
                }
            }
        }
        if ($definitions !== []) {
            $translations = MetafieldDefinitionTranslation::query()->whereIn('metafield_definition_id', array_keys($definitions))->whereIn('locale', $chain)->limit(100001)->get();
            if ($translations->count() > 100000) {
                throw new MetafieldBatchReadException('Metafield definition translations exceed the bounded batch payload.');
            }
            $byDefinition = $translations->groupBy('metafield_definition_id');
            foreach ($definitions as $definition) {
                $definition->setRelation('translations', $byDefinition->get($definition->id, new Collection));
            }
        }
        $values = $this->values($batch, $selected, $chain);
        $this->references->load($batch, array_values($definitions), array_values($values->all()));
        $current = [];
        foreach ($values as $value) {
            $type = $value->metafieldable_type;
            $id = $value->metafieldable_id;
            if (isset($current[$type][$id][$value->definition_id])) {
                $owner = $this->owner($batch, $type, $id);
                throw MetafieldIntegrityException::duplicateActiveOwnerDefinitionRecords($owner, [$value->definition_id]);
            }
            $current[$type][$id][$value->definition_id] = $value;
            $value->setRelation('definition', $definitions[$value->definition_id]);
        }
        $result = [];
        foreach ($batch->identities() as $identity) {
            $fields = [];
            foreach ($selected[$identity->type][$identity->id] ?? [] as $assignment) {
                $fields[] = OwnerMetafieldField::fromBatchAssignment($assignment, $current[$identity->type][$identity->id][$assignment->definition_id] ?? null, $locale);
            }
            $result[$identity->type][$identity->id] = new OwnerMetafieldFields($fields);
        }

        return $this->result($batch, $result);
    }

    /** @param array<string, array<array-key, OwnerMetafieldFields>> $values */
    private function result(OwnerBatch $batch, array $values): OwnersMetafields
    {
        return OwnersMetafields::fromMap(new OwnerResultMap($batch, $values));
    }

    /** Reload every owner through retained host scopes before package reads. */
    private function admit(OwnerBatch $input): OwnerBatch
    {
        $groups = [];
        foreach ($input->owners() as $index => $owner) {
            $this->registry->resolveOwnerType($owner);
            $prototype = new ($owner::class);
            if ($owner->getConnection() !== (new Metafield)->getConnection() || $prototype->getConnection() !== $owner->getConnection()
                || $prototype->getTable() !== $owner->getTable()) {
                throw new TenantBoundaryViolation('Metafield owners must retain their canonical table and package connection.');
            }
            $groups[$owner::class][] = $input->identities()[$index]->id;
        }
        $loaded = [];
        foreach ($groups as $class => $ids) {
            $prototype = new $class;
            $query = $prototype->newQuery()->withoutEagerLoads();
            MetafieldOwnerPredicate::identifiers($query, $prototype->getQualifiedKeyName(), $ids);
            if ($this->configuration->get('nvl-tenancy.enabled') === true) {
                $this->boundary->query($query, $this->resources->forModel($prototype)->key);
            }
            foreach ($query->get() as $owner) {
                $identity = OwnerIdentity::fromModel($owner);
                $loaded[$identity->type][$identity->id] = $owner;
            }
        }
        $canonical = [];
        foreach ($input->identities() as $identity) {
            $canonical[] = $loaded[$identity->type][$identity->id]
                ?? throw new TenantBoundaryViolation('A Metafield batch owner is absent, deleted or outside the active host/tenant scope.');
        }

        return OwnerBatch::fromModels($canonical);
    }

    /**
     * @param  array<string, array<array-key, array<string, MetafieldDefinitionAssignment>>>  $selected
     * @param  list<string>  $chain
     * @return Collection<int, Metafield>
     */
    private function values(OwnerBatch $batch, array $selected, array $chain): Collection
    {
        if ($selected === []) {
            return new Collection;
        }
        $query = Metafield::query()->where(function (Builder $pairs) use ($batch, $selected): void {
            foreach ($batch->identities() as $identity) {
                $ids = array_keys($selected[$identity->type][$identity->id] ?? []);
                if ($ids !== []) {
                    $pairs->orWhere(function (Builder $pair) use ($identity, $ids): void {
                        MetafieldOwnerPredicate::equal($pair, 'metafieldable_type', $identity->type);
                        MetafieldOwnerPredicate::equal($pair, 'metafieldable_id', $identity->id);
                        $pair->whereIn('definition_id', $ids);
                    });
                }
            }
        })->where(fn (Builder $nested) => $this->policy->scopeValues($nested, $batch));
        $values = $query->limit(10001)->get();
        if ($values->count() > 10000) {
            throw new MetafieldBatchReadException('Metafield batches allow at most 10,000 current values.');
        }
        if ($values->isNotEmpty()) {
            $translations = MetafieldTranslation::query()->whereIn('metafield_id', $values->modelKeys())->whereIn('locale', $chain)->limit(100001)->get();
            if ($translations->count() > 100000) {
                throw new MetafieldBatchReadException('Metafield value translations exceed the bounded batch payload.');
            }
            $byValue = $translations->groupBy('metafield_id');
            foreach ($values as $value) {
                $value->setRelation('translations', $byValue->get($value->id, new Collection));
            }
        }

        return $values;
    }

    /** Find the already admitted owner for an integrity failure. */
    private function owner(OwnerBatch $batch, string $type, string $id): Model
    {
        foreach ($batch->owners() as $owner) {
            $identity = OwnerIdentity::fromModel($owner);
            if ($identity->type === $type && $identity->id === $id) {
                return $owner;
            }
        }
        throw new MetafieldBatchReadException('A current value has an unrequested owner identity.');
    }
}
