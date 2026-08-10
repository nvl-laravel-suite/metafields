<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
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
use Nvl\Metafields\Services\ConfiguredMetafieldAuthorization;
use Nvl\Metafields\Services\ConfiguredMetafieldReferenceAuthorization;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Translatable\Services\TranslationResourceRegistry;

final class MetafieldsServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(
        TranslationResourceRegistry $translationResources,
        TypeScriptSourceRegistry $typeScriptSources,
        MetafieldOwnerRegistry $owners,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/metafields');

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
        if ((bool) config('metafields.migrations.enabled', true)) {
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
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../../config/metafields.php', 'metafields');

        $this->app->register(RouteServiceProvider::class);

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

        $this->loadTranslationsFrom($langPath, 'metafields');
        $this->loadJsonTranslationsFrom($langPath);

        $this->publishes([
            $langPath => lang_path('vendor/metafields'),
        ], 'metafields-translations');
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            __DIR__.'/../../config/metafields.php' => config_path('metafields.php'),
        ], 'metafields-config');
    }

    /**
     * Register stable owner aliases in Laravel's polymorphic relation map.
     */
    private function registerOwnerMorphMap(MetafieldOwnerRegistry $owners): void
    {
        $morphMap = [];

        foreach ($owners->all() as $alias => $configuration) {
            $morphMap[$alias] = $configuration['model'];
        }

        Relation::morphMap($morphMap, merge: true);
    }

    /**
     * Register the default authenticated management API rate limiter.
     */
    private function registerRateLimiter(): void
    {
        RateLimiter::for('metafields-management', static function (Request $request): Limit {
            $authenticatedIdentifier = $request->user()?->getAuthIdentifier();
            $identifier = is_string($authenticatedIdentifier) || is_int($authenticatedIdentifier)
                ? (string) $authenticatedIdentifier
                : ($request->ip() ?? 'unknown');

            return Limit::perMinute(
                MetafieldConfiguration::positiveInteger(
                    'metafields.routes.rate_limit_per_minute',
                    60,
                ),
            )->by($identifier);
        });
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
}
