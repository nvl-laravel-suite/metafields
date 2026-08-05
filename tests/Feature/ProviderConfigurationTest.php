<?php

declare(strict_types=1);

use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Services\MetafieldDoctor;

test('consumer configuration wins while omitted nested package defaults remain available', function (): void {
    config()->set('metafields', [
        'routes' => [
            'prefix' => 'consumer/metafields',
        ],
        'owners' => [
            'catalog' => [
                'model' => 'Domain\\Catalog\\Product',
                'label' => 'Catalog',
            ],
        ],
    ]);

    (new MetafieldsServiceProvider(app()))->register();

    expect(config('metafields.routes.prefix'))->toBe('consumer/metafields')
        ->and(config('metafields.routes.enabled'))->toBeFalse()
        ->and(config('metafields.routes.management_middleware'))
        ->toBe(['auth', 'throttle:metafields-management'])
        ->and(config('metafields.owners.catalog.model'))->toBe('Domain\\Catalog\\Product');
});

test('management routes remain absent unless explicitly enabled', function (): void {
    $this->getJson('/api/v1/metafields/owners')->assertNotFound();
});

test('doctor rejects enabled management routes without authentication and rate limiting', function (): void {
    config([
        'metafields.routes.enabled' => true,
        'metafields.routes.management_middleware' => ['api'],
    ]);

    $check = collect(app(MetafieldDoctor::class)->inspect())
        ->firstWhere('key', 'routes.management');

    expect($check?->passed)->toBeFalse()
        ->and($check?->message)->toContain('without authentication');
});
