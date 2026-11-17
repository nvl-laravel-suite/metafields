<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Models\Metafield;
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

    expect(fn () => filteredOwners('product.weight', NAN))->toThrow(MetafieldBatchReadException::class)
        ->and(fn () => filteredOwners('product.weight', INF))->toThrow(MetafieldBatchReadException::class)
        ->and(fn () => filteredOwners('product.weight', '1e500'))->toThrow(MetafieldBatchReadException::class);
});

it('handles blank null and zero numeric values consistently', function (string $type): void {
    $owners = filterableOwners('amount', $type, ['', '   ', null, '0', '5']);
    [$blank, $padded, , $zero, $five] = $owners;
    $values = Metafield::query()->pluck('value', 'metafieldable_id');
    $stored = array_map(static fn (int $owner): ?string => $values[(string) $owner], $owners);

    expect($stored)->toBe([null, null, null, '0', '5'])
        ->and(filteredOwners('product.amount', 0))->toBe([$zero])
        ->and(filteredOwners('product.amount', 0, '!='))->toBe([$five])
        ->and(filteredOwners('product.amount', -1, '>'))->toBe([$zero, $five]);

    Metafield::query()->where('metafieldable_id', $blank)->toBase()->update(['value' => '']);
    Metafield::query()->where('metafieldable_id', $padded)->toBase()->update(['value' => 'not a number']);

    expect(filteredOwners('product.amount', -1, '>'))->toBe([$zero, $five])
        ->and(filteredOwners('product.amount', 0))->toBe([$zero])
        ->and(filteredOwners('product.amount', 0, '<='))->toBe([$zero]);
})->with(['integer', 'decimal', 'float']);

it('applies one numeric grammar with the same whitespace rules on every database', function (): void {
    [$newline, $tabbed, $sign, $doubleDot] = filterableOwners('amount', 'float', ['1', '2', '3', '4']);
    foreach ([$newline => "5\n", $tabbed => " \t7.5\r", $sign => '+', $doubleDot => '1..2'] as $owner => $raw) {
        Metafield::query()->where('metafieldable_id', $owner)->toBase()->update(['value' => $raw]);
    }

    $nonNegative = filteredOwners('product.amount', 0, '>=');

    expect($nonNegative)->not->toContain($sign)
        ->and($nonNegative)->not->toContain($doubleDot)
        ->and($nonNegative)->toBe([$newline, $tabbed])
        ->and(filteredOwners('product.amount', 5))->toBe([$newline])
        ->and(filteredOwners('product.amount', 7.5))->toBe([$tabbed]);
});

it('rejects numeric values that no database numeric comparison can read', function (string $type, string $value): void {
    filterableOwners('amount', $type, ['5']);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Wide']);

    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.amount', $value, SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(filteredOwners('product.amount', 0, '>'))->toHaveCount(1)
        ->and(fn () => filteredOwners('product.amount', $value))->toThrow(MetafieldBatchReadException::class);
})->with([
    'float tiny exponent' => ['float', '1e-1001'],
    'float wide digits' => ['float', '0.'.str_repeat('1', 20000)],
    'decimal wide digits' => ['decimal', '0.'.str_repeat('1', 20000)],
]);

it('skips legacy numeric text that no database numeric comparison can read', function (string $raw): void {
    [$legacy, $five] = filterableOwners('amount', 'float', ['1', '5']);
    Metafield::query()->where('metafieldable_id', $legacy)->toBase()->update(['value' => $raw]);

    expect(filteredOwners('product.amount', 0, '>'))->toBe([$five]);
})->with([
    'tiny exponent' => ['1e-1001'],
    'wide digits' => ['0.'.str_repeat('1', 20000)],
]);

it('rejects comparison text no stored value can hold instead of comparing a truncated prefix', function (string $text): void {
    filterableOwners('code', 'string', ['a']);

    expect(fn () => filteredOwners('product.code', $text))->toThrow(MetafieldBatchReadException::class);
})->with(['NUL byte' => ["a\0b"], 'invalid UTF-8' => ["a\xFFb"]]);
