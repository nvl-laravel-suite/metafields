<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Nvl\Metafields\Support\MetafieldConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

/** Route provider for the optional Metafields management API. */
final class RouteServiceProvider extends ServiceProvider
{
    use RegistersNamespacedResources;

    protected string $name = 'Metafields';

    /** Register only the selected Metafields route family. */
    public function boot(): void
    {
        $this->map();
    }

    public function map(): void
    {
        if (! (bool) config('nvl-metafields.routes.enabled', false)) {
            return;
        }

        Route::middleware($this->middleware())
            ->prefix(trim(MetafieldConfiguration::string('nvl-metafields.routes.prefix', 'nvl/api/v1'), '/'))
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
            });
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return array_values(array_filter(
            (array) config('nvl-metafields.routes.middleware', ['api']),
            static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
        ));
    }
}
