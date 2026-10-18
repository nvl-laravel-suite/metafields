<?php

declare(strict_types=1);
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Tests\Fixtures\AlternateKeyDefinition;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipTestCase::class);
it('uses configured factories and relationships while rejecting stale bases and absent enforcement', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        expect(MetafieldDefinition::factory()->make()::class)->toBe(OwnershipMetafieldDefinition::class);
        $definition = OwnershipMetafieldScenario::definition();
        expect((new MetafieldDefinition)->resolveRouteBinding($definition->id)::class)->toBe(OwnershipMetafieldDefinition::class);
        expect((new MetafieldDefinition)->resolveSoftDeletableRouteBinding($definition->id)::class)->toBe(OwnershipMetafieldDefinition::class);
        expect($definition->translations()->first()->definition::class)->toBe(OwnershipMetafieldDefinition::class);
        expect(fn () => MetafieldDefinition::query()->get())->toThrow(OwnershipViolation::class);
        config(['nvl-ownership.enabled' => false]);
        expect(fn () => MetafieldModelRegistry::query()->get())->toThrow(OwnershipViolation::class);
    });
});

it('rejects a model subclass that changes the native package primary key', function (): void {
    $map = Relation::morphMap();
    Relation::morphMap([], false);
    try {
        $configuration = new Repository(['nvl-metafields' => ['models' => ['definition' => AlternateKeyDefinition::class]]]);
        $registry = new MetafieldModelRegistry($configuration, new OwnerRegistry($configuration));
        expect(fn () => $registry->definitionClass())->toThrow(OwnershipConfigurationInvalid::class);
    } finally {
        Relation::morphMap($map, false);
    }
});

it('resolves base-class owner and reference declarations through configured native identity', function (): void {
    $owners = config('nvl-metafields.owners');
    $owners['schemas'] = ['model' => MetafieldDefinition::class, 'label' => 'Schemas', 'sections' => ['general'], 'runtime_status' => 'live'];
    config(['nvl-metafields.owners' => $owners, 'nvl-metafields.reference_models' => []]);
    expect(app(MetafieldOwnerRegistry::class)->all()['schemas']['model'])->toBe(OwnershipMetafieldDefinition::class);
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        $definition = OwnershipMetafieldScenario::definition();
        expect(app(MetafieldReferenceRecordResolver::class)->resolve('schemas', $definition->id)::class)->toBe(OwnershipMetafieldDefinition::class);
    });
});
