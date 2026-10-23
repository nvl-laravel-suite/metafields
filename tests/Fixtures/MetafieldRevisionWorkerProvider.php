<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;

/** Configures one isolated worker against its parent's committed SQLite race fixture. */
final class MetafieldRevisionWorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $configuration = [];

    /** Configure canonical owner declarations before package providers boot. */
    public function register(): void
    {
        $this->app->make(Repository::class)->set(self::$configuration);
    }
}
