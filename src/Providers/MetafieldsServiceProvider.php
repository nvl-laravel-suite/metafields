<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\UpdateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\DeleteOwnerMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionAddCommand;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionRemoveCommand;
use Nvl\Metafields\Console\Commands\MetafieldDoctorCommand;
use Nvl\Metafields\Console\Commands\MetafieldListCommand;
use Nvl\Metafields\Contracts\CreateMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteOwnerMetafieldContract;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Contracts\SetMetafieldContract;
use Nvl\Metafields\Contracts\SyncOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\UpdateMetafieldDefinitionContract;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\ConfiguredMetafieldAuthorization;
use Nvl\Metafields\Services\ConfiguredMetafieldReferenceAuthorization;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogReader;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionImporter;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Tenancy\MetafieldAdoptionAdapter;
use Nvl\Metafields\Tenancy\MetafieldTenancyResources;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Registers Metafields' package services, ownership graph, and optional surfaces. */
final class MetafieldsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    /**
     * Boot the application events.
     */
    public function boot(
        TranslationResourceRegistry $translationResources,
        TypeScriptSourceRegistry $typeScriptSources,
        MetafieldOwnerRegistry $owners,
        MetafieldTenancyResources $tenancyResources,
        TenantResourceRegistry $tenantResources,
        TenantBoundary $tenantBoundary,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/metafields');
        $tenancyResources->register($tenantResources);
        if ($this->app->bound(TenantAdoptionRegistry::class)) {
            $this->app->make(TenantAdoptionRegistry::class)->register('metafields', MetafieldAdoptionAdapter::class);
        }
        $this->registerTenantScopes($tenantBoundary);

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'metafields-skills');
        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'metafields-migrations');

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }

        $this->registerTranslations();
        $this->registerConfig();
        $this->registerOwnerMorphMap($owners);
        $this->registerRateLimiter();
        if ((bool) config('nvl-metafields.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $translationResources->register(
            key: 'metafields.definitions',
            modelClass: MetafieldDefinition::class,
            label: 'Metafield definitions',
            searchableColumns: ['namespace', 'key', 'handle'],
            displayColumns: ['handle', 'type'],
            orderColumn: 'display_order',
        );
        $translationResources->register(
            key: 'metafields.values',
            modelClass: Metafield::class,
            label: 'Metafield values',
            searchableColumns: ['metafieldable_type', 'metafieldable_id'],
            displayColumns: ['definition_id', 'metafieldable_type', 'metafieldable_id'],
            orderColumn: 'created_at',
        );
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/metafields', fn (): array => $this->app->make(MetafieldDoctor::class)->inspect());

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-metafields.php', 'metafields');
        $this->app->singleton(MetafieldOwnerRegistry::class);

        $this->app->register(RouteServiceProvider::class);

        $this->app->scoped(MetafieldDefinitionCatalogReader::class);
        $this->app->scoped(MetafieldDefinitionImporter::class);
        $this->app->scoped(MetafieldOwnerModelResolver::class);
        $this->app->scoped(MetafieldReferenceRecordResolver::class);

        $this->app->bind(SetMetafieldContract::class, SetMetafieldAction::class);
        $this->app->bind(SyncOwnerMetafieldsContract::class, SyncOwnerMetafieldsAction::class);
        $this->app->bind(DeleteOwnerMetafieldContract::class, DeleteOwnerMetafieldAction::class);
        $this->app->bind(CreateMetafieldDefinitionContract::class, CreateMetafieldDefinitionAction::class);
        $this->app->bind(UpdateMetafieldDefinitionContract::class, UpdateMetafieldDefinitionAction::class);
        $this->app->bind(DeleteMetafieldDefinitionContract::class, DeleteMetafieldDefinitionAction::class);
        $this->app->bindIf(MetafieldAuthorization::class, ConfiguredMetafieldAuthorization::class);
        $this->app->bindIf(
            MetafieldReferenceAuthorization::class,
            ConfiguredMetafieldReferenceAuthorization::class,
        );
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            MetafieldDefinitionAddCommand::class,
            MetafieldDefinitionRemoveCommand::class,
            MetafieldListCommand::class,
            MetafieldDoctorCommand::class,
        ]);
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = __DIR__.'/../../lang';

        $this->app->make(GlobalNames::class)->translations('metafields', $langPath, $this->app->make('translation.loader'));

        $this->publishes([
            $langPath => lang_path('vendor/nvl-metafields'),
        ], 'metafields-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/nvl-metafields.php' => config_path('nvl-metafields.php'),
        ], 'metafields-config');
    }

    /**
     * Register stable owner aliases in Laravel's polymorphic relation map.
     */
    private function registerOwnerMorphMap(MetafieldOwnerRegistry $owners): void
    {
        $owners->all();
    }

    /**
     * Register the default authenticated management API rate limiter.
     */
    private function registerRateLimiter(): void
    {
        if (config('nvl-metafields.routes.enabled', false) !== true) {
            return;
        }
        $limiter = static function (Request $request): Limit {
            $authenticatedIdentifier = $request->user()?->getAuthIdentifier();
            $identifier = is_string($authenticatedIdentifier) || is_int($authenticatedIdentifier)
                ? (string) $authenticatedIdentifier
                : ($request->ip() ?? 'unknown');

            return Limit::perMinute(
                MetafieldConfiguration::positiveInteger(
                    'nvl-metafields.routes.rate_limit_per_minute',
                    60,
                ),
            )->by($identifier);
        };
        $names = $this->app->make(GlobalNames::class);
        $exists = static fn (string $name): bool => RateLimiter::limiter($name) !== null;
        $install = static function (string $name) use ($limiter): void {
            RateLimiter::for($name, $limiter);
        };
        $names->reserve('metafields', 'limiter', 'nvl.metafields.management', $exists, $install);
        $names->register('metafields', 'limiter', 'metafields-management', 'nvl.metafields.management', $exists, $install);
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return [];
    }

    /** Register package ownership scopes from the injected boundary. */
    private function registerTenantScopes(TenantBoundary $boundary): void
    {
        foreach ([
            MetafieldDefinition::class => 'metafields.definitions',
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
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     */
    private function registerTenantScope(string $model, string $resource, TenantBoundary $boundary): void
    {
        $model::addGlobalScope('tenant', static function (Builder $query) use ($boundary, $resource): void {
            $boundary->query($query, $resource);
        });
    }
}
