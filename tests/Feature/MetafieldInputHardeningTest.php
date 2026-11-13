<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    foreach (['create', 'update', 'archive', 'delete', 'write', 'read'] as $ability) {
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
 * Create one assigned definition through the native service.
 *
 * @param  array<string, mixed>  $attributes
 */
function hardeningDefinition(string $key, string $type, array $attributes = []): MetafieldDefinition
{
    return app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => $key, 'type' => $type,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => ucfirst($key)]],
    ] + $attributes, SystemActor::Background);
}

it('bounds structured values supplied as JSON strings like their decoded arrays', function (string $key, string $type, string $payload): void {
    hardeningDefinition($key, $type);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);

    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.'.$key, $payload, SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(MetafieldValues::class)->set($owner, 'product.'.$key, json_decode($payload, true, 512, JSON_THROW_ON_ERROR), SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(fn () => hardeningDefinition($key.'_default', $type, ['defaultValue' => $payload]))
        ->toThrow(ValidationException::class)
        ->and(Metafield::query()->count())->toBe(0);
})->with([
    'nested JSON' => ['data', 'json', str_repeat('[', 40).str_repeat(']', 40)],
    'oversized JSON' => ['data', 'json', '['.implode(',', range(1, 1500)).']'],
    'oversized array' => ['list', 'array', '['.implode(',', range(1, 1500)).']'],
]);

it('still accepts a bounded structured value supplied as a JSON string', function (): void {
    hardeningDefinition('data', 'json');
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $value = app(MetafieldValues::class)->set($owner, 'product.data', '{"a":[1,2,3]}', SystemActor::Background);

    expect(Metafield::query()->with('definition')->findOrFail($value->id)->getValue())->toBe(['a' => [1, 2, 3]]);
});

it('rejects float values that are not finite numbers', function (mixed $value): void {
    hardeningDefinition('weight', 'float');
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);

    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.weight', $value, SystemActor::Background))
        ->toThrow(ValidationException::class)
        ->and(fn () => hardeningDefinition('weight_default', 'float', ['defaultValue' => $value]))
        ->toThrow(ValidationException::class)
        ->and(Metafield::query()->count())->toBe(0);
})->with([
    'infinity' => [INF],
    'negative infinity' => [-INF],
    'not a number' => [NAN],
    'overflowing literal' => ['1e999'],
]);

it('keeps finite float values readable', function (): void {
    hardeningDefinition('weight', 'float');
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $value = app(MetafieldValues::class)->set($owner, 'product.weight', '1.5e3', SystemActor::Background);

    expect(Metafield::query()->with('definition')->findOrFail($value->id)->getValue())->toBe(1500.0);
});

it('reports a taken definition handle as a validation failure', function (): void {
    hardeningDefinition('headline', 'string');
    $other = hardeningDefinition('subtitle', 'string');

    expect(fn () => hardeningDefinition('headline', 'integer'))->toThrow(ValidationException::class)
        ->and(fn () => app(MetafieldDefinitions::class)->update($other, ['key' => 'headline'], $other->revision, SystemActor::Background))->toThrow(ValidationException::class)
        ->and(MetafieldModelRegistry::query()->count())->toBe(2)
        ->and($other->refresh()->handle)->toBe('product.subtitle');

    $renamed = app(MetafieldDefinitions::class)->update($other, ['key' => 'tagline'], $other->revision, SystemActor::Background);
    $archived = app(MetafieldDefinitions::class)->archive($renamed, true, $renamed->revision, SystemActor::Background);

    expect($renamed->handle)->toBe('product.tagline')
        ->and(app(MetafieldDefinitions::class)->update($archived, ['key' => 'headline'], $archived->revision, SystemActor::Background)->handle)->toBe('product.headline')
        ->and(hardeningDefinition('tagline', 'string')->handle)->toBe('product.tagline');
});

it('reports a malformed definition identifier as not found', function (string $operation): void {
    $definitions = app(MetafieldDefinitions::class);

    expect(fn () => match ($operation) {
        'update' => $definitions->update('not-a-uuid', ['isRequired' => true], 1, SystemActor::Background),
        'archive' => $definitions->archive('not-a-uuid', true, 1, SystemActor::Background),
        'delete' => $definitions->delete('not-a-uuid', 1, SystemActor::Background),
    })->toThrow(ModelNotFoundException::class);
})->with(['update', 'archive', 'delete']);

it('cascades a definition deletion over its values in bounded statements', function (): void {
    $definition = hardeningDefinition('headline', 'string', ['isTranslatable' => true]);
    foreach (range(1, 230) as $number) {
        $owner = TestMetafieldOwner::query()->create(['name' => 'Owner '.$number]);
        $value = Metafield::factory()->forDefinition($definition)->forOwner($owner)->create();
        MetafieldTranslation::query()->create(['metafield_id' => $value->id, 'locale' => 'en', 'value' => 'Headline '.$number]);
    }
    $largest = 0;
    DB::listen(static function (QueryExecuted $query) use (&$largest): void {
        $largest = max($largest, count($query->bindings));
    });

    expect(app(MetafieldDefinitions::class)->delete($definition, $definition->revision, SystemActor::Background, true))->toBeTrue()
        ->and($largest)->toBeLessThanOrEqual(101)
        ->and(Metafield::query()->count())->toBe(0)
        ->and(Metafield::withTrashed()->count())->toBe(230)
        ->and(MetafieldTranslation::query()->count())->toBe(0);
});

it('rejects duplicate JSON property schema keys and validates JSON string payloads', function (): void {
    expect(fn () => hardeningDefinition('spec', 'json', [
        'jsonPropertySchema' => [
            ['key' => 'weight', 'type' => 'float', 'isRequired' => true],
            ['key' => 'weight', 'type' => 'string', 'isRequired' => false],
        ],
    ]))->toThrow(ValidationException::class);

    $definition = hardeningDefinition('spec', 'json', [
        'jsonPropertySchema' => [
            ['key' => 'weight', 'type' => 'float', 'isRequired' => true],
        ],
        'defaultValue' => '{"weight": 12.5}',
    ]);

    expect($definition->getSerializableDefaultValue())->toBe(['weight' => 12.5]);
});
