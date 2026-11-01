<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionAddCommand;
use Nvl\Metafields\Console\Commands\MetafieldDefinitionRemoveCommand;
use Nvl\Metafields\Console\Commands\MetafieldDoctorCommand;
use Nvl\Metafields\Console\Commands\MetafieldListCommand;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Metafields\Contracts\MetafieldDiagnosticAccess;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Ownership\MetafieldAdoptionAdapter;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Ownership\MetafieldResourceRegistrar;
use Nvl\Metafields\Services\ConfiguredMetafieldAuthorization;
use Nvl\Metafields\Services\ConfiguredMetafieldReferenceAuthorization;
use Nvl\Metafields\Services\DenyMetafieldCatalogAccess;
use Nvl\Metafields\Services\DenyMetafieldDiagnosticAccess;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Services\UnsupportedMetafieldBatchAuthorization;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\Ownership\Contracts\OwnershipIntegratedProvider;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;
use Nvl\Translatable\Services\TranslationResourceRegistry;

/** Registers the native Metafield domain and optional ownership integration. */
final class MetafieldsServiceProvider extends ServiceProvider implements OwnershipIntegratedProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        PackageDoctorContributor::register($this->app, 'nvl/metafields', fn (): array => $this->app->make(MetafieldDoctor::class)->inspect());
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-metafields.php', 'metafields');
        $this->app->singleton(MetafieldOwnerRegistry::class);
        $this->app->singleton(MetafieldModelRegistry::class);
        $this->app->booting(fn () => $this->app->make(MetafieldModelRegistry::class)->prepare());
        $this->app->scoped(MetafieldOwnerModelResolver::class);
        $this->app->scoped(MetafieldReferenceRecordResolver::class);
        $this->app->bindIf(MetafieldCatalogAccess::class, DenyMetafieldCatalogAccess::class);
        $this->app->bindIf(MetafieldDiagnosticAccess::class, DenyMetafieldDiagnosticAccess::class);
        $this->app->bindIf(MetafieldAuthorization::class, ConfiguredMetafieldAuthorization::class);
        $this->app->bindIf(MetafieldBatchAuthorization::class, function (): MetafieldBatchAuthorization {
            /** @var MetafieldAuthorization $authorization */
            $authorization = $this->app->make(MetafieldAuthorization::class);

            return $authorization instanceof MetafieldBatchAuthorization
                ? $authorization : new UnsupportedMetafieldBatchAuthorization;
        });
        $this->app->bindIf(MetafieldReferenceAuthorization::class, ConfiguredMetafieldReferenceAuthorization::class);
    }

    public function boot(TranslationResourceRegistry $translationResources, MetafieldOwnerRegistry $owners): void
    {
        $this->app->make(MetafieldResourceRegistrar::class)->register($this->app->make(OwnershipResourceRegistry::class));
        if ($this->app->bound(OwnershipAdoptionRegistry::class)) {
            $this->app->make(OwnershipAdoptionRegistry::class)->register('metafields', MetafieldAdoptionAdapter::class);
        }
        foreach ([Metafield::class, MetafieldTranslation::class] as $model) {
            $model::addGlobalScope('metafield-ownership-configuration', static function (Builder $query): void {
                MetafieldOwnership::declared();
            });
        }
        $this->publishes([__DIR__.'/../../resources/boost/skills' => base_path('.agents/skills')], 'nvl-metafields-skills');
        $this->publishesMigrations([__DIR__.'/../../database/migrations' => database_path('migrations')], 'nvl-metafields-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([
                MetafieldDefinitionAddCommand::class,
                MetafieldDefinitionRemoveCommand::class,
                MetafieldListCommand::class,
                MetafieldDoctorCommand::class,
            ]);
        }
        $langPath = __DIR__.'/../../lang';
        $this->app->make(GlobalNames::class)->translations('metafields', $langPath, $this->app->make('translation.loader'));
        $this->publishes([$langPath => lang_path('vendor/nvl-metafields')], 'nvl-metafields-translations');
        $this->publishes([__DIR__.'/../../config/nvl-metafields.php' => config_path('nvl-metafields.php')], 'nvl-metafields-config');
        $owners->all();
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

    /** @return list<string> */
    public function provides(): array
    {
        return [];
    }
}
