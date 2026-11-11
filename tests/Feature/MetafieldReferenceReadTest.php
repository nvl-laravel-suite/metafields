<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    foreach (['create', 'write', 'read'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    }
    Gate::define('metafields.owner', static fn (SystemActor $actor): bool => true);
    Gate::define('metafields.reference', static fn (SystemActor $actor): bool => true);
    config([
        'nvl-metafields.authorization.owner_ability' => 'metafields.owner',
        'nvl-metafields.authorization.reference_ability' => 'metafields.reference',
        'nvl-translatable.locales' => ['en'],
        'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']]],
    ]);
    foreach (['related' => 'reference', 'bundle' => 'reference_list'] as $key => $type) {
        app(MetafieldDefinitions::class)->create([
            'namespace' => 'product', 'key' => $key, 'type' => $type, 'referencedModelType' => 'products',
            'assignment' => ['ownerType' => 'products', 'section' => 'general'],
            'translations' => ['en' => ['title' => ucfirst($key)]],
        ], SystemActor::Background);
    }
});

it('resolves a reference list in stored order with a query count independent of its size', function (): void {
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $targets = collect(range(1, 5))->map(static fn (int $number): TestMetafieldOwner => TestMetafieldOwner::query()->create(['name' => 'Target '.$number]));
    $ids = $targets->map(static fn (TestMetafieldOwner $target): string => (string) $target->id)->reverse()->values()->all();
    $measure = function (array $ids) use ($owner): array {
        $existing = Metafield::query()->first();
        $value = app(MetafieldValues::class)->set($owner, 'product.bundle', $ids, SystemActor::Background, null, $existing?->revision);
        $value = Metafield::query()->with('definition')->findOrFail($value->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resolved = $value->getValue();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$resolved->map(static fn (TestMetafieldOwner $target): string => (string) $target->id)->all(), $queries];
    };

    [$one, $oneQueries] = $measure([$ids[0]]);
    [$many, $manyQueries] = $measure([...$ids, $ids[0]]);

    expect($one)->toBe([$ids[0]])
        ->and($many)->toBe([...$ids, $ids[0]])
        ->and($manyQueries)->toBe($oneQueries);
});

it('omits reference list targets that no longer exist', function (): void {
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $kept = TestMetafieldOwner::query()->create(['name' => 'Kept']);
    $removed = TestMetafieldOwner::query()->create(['name' => 'Removed']);
    $value = app(MetafieldValues::class)->set($owner, 'product.bundle', [(string) $removed->id, (string) $kept->id], SystemActor::Background);
    $removed->delete();

    expect(Metafield::query()->findOrFail($value->id)->getValue()->map(static fn (TestMetafieldOwner $target): int => $target->id)->all())->toBe([$kept->id]);
});

it('rejects identifiers an integer reference key cannot hold instead of sending them to the database', function (string $suffix, string $prefix): void {
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $target = TestMetafieldOwner::query()->create(['name' => 'Target']);
    $identifier = $prefix.$target->id.$suffix;

    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.related', $identifier, SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(MetafieldValues::class)->set($owner, 'product.bundle', [$identifier], SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(Metafield::query()->count())->toBe(0);
})->with([
    'trailing text' => ['abc', ''],
    'exponent' => ['e1', ''],
    'leading zero' => ['', '0'],
    'explicit sign' => ['', '+'],
    'out of range' => ['99999999999999999999', ''],
    'text only' => ['', 'target-'],
]);
