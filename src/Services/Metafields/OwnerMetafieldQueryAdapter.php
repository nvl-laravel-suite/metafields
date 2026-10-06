<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\Metafields;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Relations\NumericMetafieldComparison;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Compares stored scalar values with exact correlated owner identities. */
final readonly class OwnerMetafieldQueryAdapter
{
    /** Retain explicit capability and active ownership dependencies. */
    public function __construct(
        private MetafieldOwnerRegistry $registry,
        private Repository $configuration,
        private TenantResourceRegistry $resources,
        private TenantBoundary $boundary,
    ) {}

    /**
     * Defaults and missing values never match this stored-value-only predicate.
     * The policy must express owner, definition and value visibility in SQL.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function apply(Builder $query, string $handle, string|int|float|bool|null $value, MetafieldBatchAuthorization $policy, string $operator = '='): Builder
    {
        if (! in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
            throw new MetafieldBatchReadException('whereNvlMetafield supports only =, !=, <, <=, > and >=.');
        }
        $owner = $query->getModel();
        $prototype = new ($owner::class);
        if ($owner->getConnection() !== (new Metafield)->getConnection() || $query->getConnection() !== $owner->getConnection()
            || $prototype->getTable() !== $owner->getTable() || $prototype->getConnection() !== $owner->getConnection()
            || $query->getQuery()->from !== $owner->getTable() || $query->getQuery()->unions !== null) {
            throw new TenantBoundaryViolation('whereNvlMetafield requires the canonical owner table and package connection without unions.');
        }
        $alias = $this->registry->resolveOwnerType($owner);
        $this->groupCallerPredicates($query);
        if (in_array(SoftDeletes::class, class_uses_recursive($prototype), true) && method_exists($prototype, 'getQualifiedDeletedAtColumn')) {
            $column = $prototype->getQualifiedDeletedAtColumn();
            if (! is_string($column) || $column === '') {
                throw new TenantBoundaryViolation('A canonical Metafield owner requires a valid soft-delete column.');
            }
            $query->whereNull($column);
        }
        if ($this->configuration->get('nvl-tenancy.enabled') === true) {
            $this->boundary->query($query, $this->resources->forModel($prototype)->key);
        }
        $definition = MetafieldDefinition::query()->active()->where('active_handle', $handle)->where('is_filterable', true)->first();
        if (! $definition instanceof MetafieldDefinition) {
            throw new MetafieldBatchReadException('whereNvlMetafield requires an active filterable definition.');
        }
        if ($definition->is_translatable || in_array($definition->type, [MetafieldTypeEnum::Reference, MetafieldTypeEnum::ReferenceList, MetafieldTypeEnum::Json, MetafieldTypeEnum::ArrayValue, MetafieldTypeEnum::RichText], true)) {
            throw new MetafieldBatchReadException('Localized, reference and structured Metafield comparisons require a separately supported SQL adapter.');
        }
        $values = Metafield::query()->where('definition_id', $definition->id)
            ->whereHas('definition', static function (Builder $definitions) use ($alias): void {
                $definitions->whereNotNull('active_handle')->whereHas('assignments',
                    static fn (Builder $assignments) => $assignments->getQuery()->where('owner_type', $alias)->where('is_active', true));
            });
        MetafieldOwnerPredicate::equal($values, (new Metafield)->qualifyColumn('metafieldable_type'), $owner->getMorphClass());
        MetafieldOwnerPredicate::columns($values, (new Metafield)->qualifyColumn('metafieldable_id'), $owner->getQualifiedKeyName());
        $values->where(fn (Builder $nested) => $policy->scopeHostValues($nested, $prototype, $handle));
        if ($value === null) {
            if (! in_array($operator, ['=', '!='], true)) {
                throw new MetafieldBatchReadException('Null Metafield comparisons support only = and !=.');
            }
            $values->whereNull('value', 'and', $operator === '!=');
        } else {
            $encoded = $definition->type->storeCast($value);
            if (! is_string($encoded) && ! is_int($encoded) && ! is_float($encoded)) {
                throw new MetafieldBatchReadException('Metafield comparison values must have scalar storage encoding.');
            }
            if (in_array($definition->type, [MetafieldTypeEnum::Integer, MetafieldTypeEnum::Decimal, MetafieldTypeEnum::Float], true)) {
                if (! is_numeric($encoded)) {
                    throw new MetafieldBatchReadException('Numeric Metafield comparisons require a numeric value.');
                }
                $column = $values->getQuery()->getGrammar()->wrap((new Metafield)->qualifyColumn('value'));
                $values->whereRaw(new NumericMetafieldComparison($column, $operator, $values->getModel()->getConnection()->getDriverName()), [$encoded]);
            } else {
                $values->where('value', $operator, $encoded);
            }
        }

        return $query->whereExists($values->selectRaw('1')->toBase());
    }

    /** Keep caller OR clauses inside every subsequently added mandatory owner guard.
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function groupCallerPredicates(Builder $query): void
    {
        $base = $query->getQuery();
        if ($base->wheres === []) {
            return;
        }
        $nested = $base->forNestedWhere();
        $nested->wheres = $base->wheres;
        $nested->setBindings($base->getRawBindings()['where'], 'where');
        $base->wheres = [];
        $base->setBindings([], 'where');
        $base->addNestedWhereQuery($nested);
    }
}
