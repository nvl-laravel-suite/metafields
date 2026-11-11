<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    foreach (['create', 'write', 'read'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    }
    Gate::define('metafields.owner', static fn (SystemActor $actor): bool => true);
    config([
        'nvl-metafields.authorization.owner_ability' => 'metafields.owner',
        'nvl-translatable.locales' => ['en'],
        'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']]],
    ]);
});

/**
 * Store one filterable value per owner and return the owner keys in creation order.
 *
 * @param  list<mixed>  $values
 * @return list<int>
 */
function filterableOwners(string $key, string $type, array $values): array
{
    app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => $key, 'type' => $type, 'isFilterable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => ucfirst($key)]],
    ], SystemActor::Background);
    $owners = [];
    foreach ($values as $index => $value) {
        $owner = TestMetafieldOwner::query()->create(['name' => 'Owner '.$index]);
        app(MetafieldValues::class)->set($owner, 'product.'.$key, $value, SystemActor::Background);
        $owners[] = $owner->id;
    }

    return $owners;
}

/** @return list<int> */
function filteredOwners(string $handle, string|int|float|bool|null $value, string $operator = '='): array
{
    return TestMetafieldOwner::query()
        ->whereNvlMetafield($handle, $value, SystemActor::Background, new BatchMetafieldPolicy, $operator)
        ->orderBy('id')->pluck('id')->all();
}

it('compares stored text exactly on every database', function (): void {
    [$lower, $upper, $padded, $wildcard] = filterableOwners('code', 'string', ['abc', 'ABC', 'abc ', 'a%c']);

    expect(filteredOwners('product.code', 'abc'))->toBe([$lower])
        ->and(filteredOwners('product.code', 'ABC'))->toBe([$upper])
        ->and(filteredOwners('product.code', 'abc '))->toBe([$padded])
        ->and(filteredOwners('product.code', 'a%c'))->toBe([$wildcard])
        ->and(filteredOwners('product.code', 'abc', '!='))->toBe([$upper, $padded, $wildcard]);
});

it('compares stored numbers and booleans by value', function (): void {
    [$small, $large, $exponent] = filterableOwners('weight', 'float', ['9.5', '10', '1.2e1']);
    [$enabled, $disabled] = filterableOwners('enabled', 'boolean', [true, false]);

    expect(filteredOwners('product.weight', 10, '<'))->toBe([$small])
        ->and(filteredOwners('product.weight', '10.0'))->toBe([$large])
        ->and(filteredOwners('product.weight', 10, '>'))->toBe([$exponent])
        ->and(filteredOwners('product.enabled', true))->toBe([$enabled])
        ->and(filteredOwners('product.enabled', false))->toBe([$disabled]);
});
