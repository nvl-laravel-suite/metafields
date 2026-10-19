<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Metafields\Actions\GrantMetafieldDefinitionToTenantAction;
use Nvl\Metafields\Actions\ImportPlatformMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\ArchiveMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\ListMetafieldDefinitionsAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\UpdateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\DeleteOwnerMetafieldAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnersMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Actions\RevokeMetafieldDefinitionTenantGrantAction;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionAddCommand;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionRemoveCommand;
use Nvl\Metafields\Console\Commands\MetafieldDoctorCommand;
use Nvl\Metafields\Console\Commands\MetafieldListCommand;
use Nvl\Metafields\Contracts\ArchiveMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\CreateMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteOwnerMetafieldContract;
use Nvl\Metafields\Contracts\GrantMetafieldDefinitionToTenantContract;
use Nvl\Metafields\Contracts\ImportPlatformMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\ListAuthorizedOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\ListMetafieldDefinitionsContract;
use Nvl\Metafields\Contracts\ListOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Contracts\RevokeMetafieldDefinitionTenantGrantContract;
use Nvl\Metafields\Contracts\SetMetafieldContract;
use Nvl\Metafields\Contracts\SyncOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\UpdateMetafieldDefinitionContract;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Events\MetafieldSetEvent;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Events\MetafieldsSyncedEvent;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Ownership\MetafieldAdoptionAdapter as OwnershipMetafieldAdoptionAdapter;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Ownership\MetafieldResourceRegistrar;
use Nvl\Metafields\Services\ConfiguredMetafieldAuthorization;
use Nvl\Metafields\Services\ConfiguredMetafieldReferenceAuthorization;
use Nvl\Metafields\Services\DenyMetafieldCatalogAccess;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalogReader;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionImporter;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Services\UnsupportedMetafieldBatchAuthorization;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Tenancy\MetafieldAdoptionAdapter;
use Nvl\Metafields\Tenancy\MetafieldTenancyResources;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Events\EventAliases;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Tenancy\Services\TenantAdoptionRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Registers Metafields' package services, ownership graph, and optional surfaces. */
final class MetafieldsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
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
        OwnershipBoundary $tenantBoundary,
    ): void {
        $this->app->make(EventAliases::class)->register(MetafieldSet::class, MetafieldSetEvent::class);
        $this->app->make(EventAliases::class)->register(MetafieldsSynced::class, MetafieldsSyncedEvent::class);
        $typeScriptSources->register(__DIR__.'/..', 'nvl/metafields');
        $tenancyResources->register($tenantResources);
        $this->app->make(MetafieldResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('metafields', OwnershipMetafieldAdoptionAdapter::class);
        }
        if ($this->app->bound(TenantAdoptionRegistry::class)) {
            $this->app->make(TenantAdoptionRegistry::class)->register('metafields', MetafieldAdoptionAdapter::class);
        }
        foreach ([Metafield::class, MetafieldTranslation::class] as $model) {
            $model::addGlobalScope('metafield-ownership-configuration', static function (Builder $query): void {
                MetafieldOwnership::declared();
            });
        }
        if (config('nvl-tenancy.enabled') === true) {
            $this->registerTenantScopes($tenantBoundary);
        }

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
        $this->registerOwnerCapabilities($owners);
        $this->registerRateLimiter();
        if ((bool) config('nvl-metafields.migrations.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $translationResources->register(
            key: 'metafields.definitions',
            modelClass: MetafieldModelRegistry::modelClass(),
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
        $this->app->bindIf(GrantMetafieldDefinitionToTenantContract::class, GrantMetafieldDefinitionToTenantAction::class);
        $this->app->bindIf(ImportPlatformMetafieldDefinitionContract::class, ImportPlatformMetafieldDefinitionAction::class);
        $this->app->bindIf(ArchiveMetafieldDefinitionContract::class, ArchiveMetafieldDefinitionAction::class);
        $this->app->bindIf(ListMetafieldDefinitionsContract::class, ListMetafieldDefinitionsAction::class);
        $this->app->bindIf(ListAuthorizedOwnerMetafieldsContract::class, ListAuthorizedOwnerMetafieldsAction::class);
        $this->app->bindIf(ListOwnerMetafieldsContract::class, ListOwnerMetafieldsAction::class);
        $this->app->bindIf(RevokeMetafieldDefinitionTenantGrantContract::class, RevokeMetafieldDefinitionTenantGrantAction::class);

        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/metafields', fn (): array => $this->app->make(MetafieldDoctor::class)->inspect());

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-metafields.php', 'metafields');
        $this->app->singleton(MetafieldOwnerRegistry::class);
        $this->app->bindIf(MetafieldCatalogAccess::class, DenyMetafieldCatalogAccess::class);
        $this->app->singleton(MetafieldModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(MetafieldModelRegistry::class)->prepare());

        $this->app->register(RouteServiceProvider::class);

        $this->app->scoped(MetafieldDefinitionCatalogReader::class);
        $this->app->scoped(MetafieldDefinitionImporter::class);
        $this->app->scoped(MetafieldOwnerModelResolver::class);
        $this->app->scoped(MetafieldReferenceRecordResolver::class);

        $this->app->bindIf(SetMetafieldContract::class, SetMetafieldAction::class);
        $this->app->bindIf(SyncOwnerMetafieldsContract::class, SyncOwnerMetafieldsAction::class);
        $this->app->bindIf(DeleteOwnerMetafieldContract::class, DeleteOwnerMetafieldAction::class);
        $this->app->bindIf(CreateMetafieldDefinitionContract::class, CreateMetafieldDefinitionAction::class);
        $this->app->bindIf(UpdateMetafieldDefinitionContract::class, UpdateMetafieldDefinitionAction::class);
        $this->app->bindIf(DeleteMetafieldDefinitionContract::class, DeleteMetafieldDefinitionAction::class);
        $this->app->bindIf(MetafieldAuthorization::class, ConfiguredMetafieldAuthorization::class);
        $this->app->bindIf(ListAuthorizedOwnersMetafieldsContract::class, ListAuthorizedOwnersMetafieldsAction::class);
        $this->app->bindIf(MetafieldBatchAuthorization::class, function (): MetafieldBatchAuthorization {
            return $this->batchAuthorization($this->app->make(MetafieldAuthorization::class));
        });
        $this->app->bindIf(
            MetafieldReferenceAuthorization::class,
            ConfiguredMetafieldReferenceAuthorization::class,
        );
    }

    /** Derive only from an explicitly compatible active authorization binding. */
    private function batchAuthorization(MetafieldAuthorization $authorization): MetafieldBatchAuthorization
    {
        return $authorization instanceof MetafieldBatchAuthorization
            ? $authorization : new UnsupportedMetafieldBatchAuthorization;
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
     * Validate Metafields capabilities without installing host morph aliases.
     */
    private function registerOwnerCapabilities(MetafieldOwnerRegistry $owners): void
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
    private function registerTenantScopes(OwnershipBoundary $boundary): void
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
