<?php

declare(strict_types=1);

use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyScenario;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

it('keeps an identical definition handle and owner values local to each tenant', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definitionA = $scenario->definition($scenario::A);
    $definitionB = $scenario->definition($scenario::B);
    $ownerB = $scenario->owner($scenario::B);

    expect($definitionA->id)->not->toBe($definitionB->id);
    expect(fn () => $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)
        ->execute($ownerB, 'catalog.color', 'foreign')))->toThrow(TenantBoundaryViolation::class);
});

it('supports null clear restore and tenant-local defaults without leaking history', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definition = $scenario->definition($scenario::A, 'material');
    $owner = $scenario->owner($scenario::A);

    $stored = $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)
        ->execute($owner, $definition->handle, 'cotton'));
    $stored->delete();
    $restored = $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)
        ->execute($owner, $definition->handle, 'linen'));

    expect($restored->tenant_id)->toBe($scenario::A)
        ->and($restored->getValue())->toBe('linen')
        ->and($restored->deleted_at)->toBeNull();
});

it('denies retained eager-loaded definition graphs after a tenant switch', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definition = $scenario->definition($scenario::A)->load('translations');

    expect(fn () => $scenario->run($scenario::B, fn () => $definition->displayTitle()))
        ->toThrow(TenantBoundaryViolation::class);
});

it('rejects client ownership keys and foreign direct definition models', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definition = $scenario->definition($scenario::A);

    expect(fn () => $scenario->run($scenario::B, fn () => MetafieldDefinition::query()->findOrFail($definition->id)))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($definition->getFillable())->not->toContain('tenant_id', 'ownership_key');
});
