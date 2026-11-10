<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nvl\Metafields\Enums\MetafieldAbility;

/** Rebuilds the same registered native owner and explicit actor policies in each CAS process. */
final class MetafieldCasWorkerProvider extends ServiceProvider
{
    /** @var array<string, mixed> */
    public static array $source = [];

    /** Configure the committed writer before worker package providers boot. */
    public function register(): void
    {
        $source = self::$source;
        unset($source['name']);
        $this->app->make('config')->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'database.default' => 'metafields_cas',
            'database.connections.metafields_cas' => $source,
            'nvl-core.connection' => null,
            'nvl-core.owners' => [TestMetafieldOwner::class, TestMetafieldActor::class],
            'nvl-metafields.connection' => null,
            'nvl-metafields.migrations.enabled' => false,
            'nvl-metafields.authorization.owner_ability' => 'metafields.cas-owner',
            'nvl-metafields.owners' => ['products' => [
                'model' => TestMetafieldOwner::class, 'sections' => ['general'], 'runtime_status' => 'live',
            ]],
            'nvl-translatable.locales' => ['en', 'bg'],
            'nvl-translatable.fallback_locales' => ['en'],
            'nvl-ownership.enabled' => false,
            'nvl-tenancy.enabled' => false,
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
    }

    /** Require the persisted canonical actor and exact registered owner for every mutation. */
    public function boot(): void
    {
        foreach (['create', 'write'] as $ability) {
            Gate::define('metafields.'.$ability, static fn (TestMetafieldActor $actor): bool => $actor->getKey() === 1 && $actor->name === 'CAS operator');
        }
        Gate::define('metafields.cas-owner', static fn (TestMetafieldActor $actor, string $ability, TestMetafieldOwner $owner): bool => $actor->getKey() === 1
            && $actor->name === 'CAS operator' && $ability === MetafieldAbility::MutateOwner->value
            && $owner->getKey() === 1 && $owner->getAttribute('name') === 'Concurrent owner');
    }
}
