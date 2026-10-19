<?php

declare(strict_types=1);

namespace Nvl\Metafields\Compatibility;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Tenancy\MetafieldAdoptionAdapter;
use Nvl\Metafields\Tenancy\MetafieldTenancyResources;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipSupport;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;

/** Contains only retained legacy graph registration and tenant predicates during the migration window. @internal */
final readonly class LegacyMetafieldsIntegration
{
    /** Retain provider infrastructure without storing an execution context. */
    public function __construct(private Container $app, private LegacyOwnershipSupport $legacy) {}

    /** Register the historical graph even for unowned hosts that still use its model metadata. */
    public function registerResources(): void
    {
        $this->app->make(MetafieldTenancyResources::class)->register($this->app->make(TenantResourceRegistry::class));
        if ($this->app->bound(TenantAdoptionRegistry::class)) {
            $this->app->make(TenantAdoptionRegistry::class)->register('metafields', MetafieldAdoptionAdapter::class);
        }
    }

    /** Install legacy predicates only when the legacy authority is explicitly selected. */
    public function bootScopes(): void
    {
        if ($this->legacy->enabled()) {
            $this->registerScopes($this->app->make(OwnershipBoundary::class));
        }
    }

    /** Register package ownership scopes from the injected boundary. */
    public function registerScopes(OwnershipBoundary $boundary): void
    {
        foreach ([
            MetafieldModelRegistry::modelClass() => 'metafields.definitions',
            MetafieldDefinitionAssignment::class => 'metafields.definition-assignments',
            MetafieldDefinitionTranslation::class => 'metafields.definition-translations',
            Metafield::class => 'metafields.values',
            MetafieldTranslation::class => 'metafields.value-translations',
            MetafieldDefinitionTenantGrant::class => 'metafields.catalog-grants',
        ] as $model => $resource) {
            $this->registerTenantScope($model, $resource, $boundary);
        }
    }

    /**
     * Register one model-specific tenant scope without collapsing invariant builder generics.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     */
    private function registerTenantScope(string $model, string $resource, OwnershipBoundary $boundary): void
    {
        $model::addGlobalScope('tenant', static function (Builder $query) use ($boundary, $resource): void {
            $boundary->query($query, $resource);
        });
    }
}
