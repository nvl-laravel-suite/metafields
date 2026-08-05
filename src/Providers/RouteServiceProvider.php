<?php

declare(strict_types=1);

namespace Nvl\Metafields\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Nvl\Metafields\Support\MetafieldConfiguration;

/** Route provider for the optional Metafields management API. */
final class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Metafields';

    public function map(): void
    {
        if (! (bool) config('metafields.routes.enabled', false)) {
            return;
        }

        Route::middleware($this->middleware())
            ->prefix(trim(MetafieldConfiguration::string('metafields.routes.prefix', 'api/v1'), '/'))
            ->group(__DIR__.'/../../routes/api.php');
    }

    /**
     * @return list<string>
     */
    private function middleware(): array
    {
        return array_values(array_filter(
            (array) config('metafields.routes.middleware', ['api']),
            static fn (mixed $middleware): bool => is_string($middleware) && $middleware !== '',
        ));
    }
}
