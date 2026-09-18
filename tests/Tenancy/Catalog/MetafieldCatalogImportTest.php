<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Metafields\Actions\GrantMetafieldDefinitionToTenantAction;
use Nvl\Metafields\Actions\ImportPlatformMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\RevokeMetafieldDefinitionTenantGrantAction;
use Nvl\Metafields\Data\AssignMetafieldDefinitionPayload;
use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

function metafieldCatalogRequest(object $grant, object $source, string $key = 'local-color', ?string $idempotencyKey = null): ImportPlatformMetafieldDefinitionData
{
    return new ImportPlatformMetafieldDefinitionData(
        $grant->id,
        $grant->revision,
        $source->revision,
        $idempotencyKey ?? (string) Str::uuid(),
        'catalog',
        $key,
        new AssignMetafieldDefinitionPayload('test-owner', 'general'),
    );
}

it('imports a definition without retaining a live platform dependency', function (): void {
    $scenario = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platformDefinition();
    $grant = $scenario->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    $copy = $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source)));
    $scenario->platform(fn () => app(RevokeMetafieldDefinitionTenantGrantAction::class)
        ->execute($grant->id, $grant->revision));
    $owner = $scenario->owner($scenario::A);
    $value = $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)
        ->execute($owner, 'catalog.local-color', 'blue'));

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->catalog_source_id)->toBe($source->id)
        ->and($value->definition_id)->toBe($copy->id);
});

it('makes exact idempotency replay stable and rejects payload drift', function (): void {
    $scenario = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platformDefinition();
    $grant = $scenario->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    $key = (string) Str::uuid();
    $request = metafieldCatalogRequest($grant, $source, idempotencyKey: $key);
    $first = $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)->execute($request));
    $replay = $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)->execute($request));

    expect($replay->id)->toBe($first->id);
    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source, 'changed', $key))))
        ->toThrow(TenantBoundaryViolation::class);
});

it('rejects handle collision stale revision and revoked grants atomically', function (): void {
    $scenario = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platformDefinition();
    $grant = $scenario->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    $scenario->definition($scenario::A, 'local-color');

    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source))))->toThrow(TenantBoundaryViolation::class);
    $scenario->platform(fn () => app(RevokeMetafieldDefinitionTenantGrantAction::class)
        ->execute($grant->id, $grant->revision));
    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source, 'after-revoke'))))
        ->toThrow(TenantBoundaryViolation::class);
});

it('requires an exact total reference map and never copies source reference ids', function (): void {
    expect((new ImportPlatformMetafieldDefinitionData(
        (string) Str::uuid(), 1, 1, (string) Str::uuid(), 'catalog', 'copy',
        new AssignMetafieldDefinitionPayload('test-owner', 'general'),
    ))->referenceMap)->toBe([]);
});

it('retains a committed copy after deleting its platform source', function (): void {
    $scenario = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platformDefinition();
    $grant = $scenario->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    $copy = $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source)));
    $scenario->platform(static function () use ($source): void {
        MetafieldDefinition::withoutGlobalScope('tenant')->findOrFail($source->id)->forceDelete();
    });

    expect($scenario->run($scenario::A, fn () => MetafieldDefinition::query()->findOrFail($copy->id))->id)
        ->toBe($copy->id);
});

it('copies every locale and rejects a stale source snapshot', function (): void {
    $scenario = MetafieldTenancyScenario::install(catalogCopies: true);
    $source = $scenario->platformDefinition();
    $grant = $scenario->platform(fn () => app(GrantMetafieldDefinitionToTenantAction::class)
        ->execute($source->id, new TenantId($scenario::A), $source->revision));
    $copy = $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source)));

    $locales = $scenario->run(
        $scenario::A,
        fn (): array => $copy->translations->pluck('locale')->all(),
    );

    expect($locales)->toBe(['en'])
        ->and($copy->catalog_source_revision)->toBe($source->revision)
        ->and($copy->catalog_source_hash)->toMatch('/^[a-f0-9]{64}$/');

    $scenario->platform(static function () use ($source): void {
        $locked = MetafieldDefinition::withoutGlobalScope('tenant')->findOrFail($source->id);
        $locked->revision++;
        $locked->save();
    });
    expect(fn () => $scenario->run($scenario::A, fn () => app(ImportPlatformMetafieldDefinitionAction::class)
        ->execute(metafieldCatalogRequest($grant, $source, 'stale'))))
        ->toThrow(TenantBoundaryViolation::class);
});
