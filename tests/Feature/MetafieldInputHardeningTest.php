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

it('normalizes JSON property requiredness', function (mixed $required): void {
    $schema = static fn (mixed $isRequired): array => ['jsonPropertySchema' => [
        ['key' => 'weight', 'type' => 'float', 'isRequired' => $isRequired],
        ['key' => 'note', 'type' => 'string', 'isRequired' => false],
    ]];
    $definition = hardeningDefinition('spec', 'json', $schema($required));
    hardeningDefinition('optional_spec', 'json', $schema(false));
    $set = static fn (string $handle, array $value): mixed => app(MetafieldValues::class)
        ->set(TestMetafieldOwner::query()->create(['name' => 'Owner']), $handle, $value, SystemActor::Background)->getValue();

    expect($definition->json_property_schema[0]['isRequired'])->toBeTrue()
        ->and($set('product.optional_spec', ['weight' => null]))->toBe(['weight' => null])
        ->and($set('product.optional_spec', ['note' => 'Fragile']))->toBe(['note' => 'Fragile'])
        ->and(fn () => $set('product.spec', ['weight' => null]))->toThrow(ValidationException::class)
        ->and(fn () => $set('product.spec', ['note' => 'Fragile']))->toThrow(ValidationException::class)
        ->and($set('product.spec', ['weight' => 1.5]))->toBe(['weight' => 1.5]);
})->with([1, '1', true]);

it('enforces combined handle storage length', function (): void {
    $create = static fn (string $key): MetafieldDefinition => app(MetafieldDefinitions::class)->create([
        'namespace' => str_repeat('n', 127), 'key' => $key, 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Long']],
    ], SystemActor::Background);

    expect(fn () => $create(str_repeat('k', 128)))->toThrow(ValidationException::class);
    $definition = $create(str_repeat('k', 127));
    expect(strlen($definition->handle))->toBe(255)
        ->and(fn () => app(MetafieldDefinitions::class)->update($definition, ['key' => str_repeat('k', 128)], $definition->revision, SystemActor::Background))
        ->toThrow(ValidationException::class);
});

it('validates array and JSON reference defaults equivalently', function (bool $asJson): void {
    $target = TestMetafieldOwner::query()->create(['name' => 'Target']);
    $default = static fn (array $identifiers): array|string => $asJson ? json_encode($identifiers, JSON_THROW_ON_ERROR) : $identifiers;

    expect(fn () => hardeningDefinition('missing_targets', 'reference_list', [
        'referencedModelType' => 'products', 'defaultValue' => $default([(string) $target->id, '999999']),
    ]))->toThrow(ValidationException::class)
        ->and(MetafieldDefinition::query()->count())->toBe(0);

    $definition = hardeningDefinition('targets', 'reference_list', [
        'referencedModelType' => 'products', 'defaultValue' => $default([(string) $target->id]),
    ]);
    expect($definition->getSerializableDefaultValue())->toBe([(string) $target->id]);
})->with(['array' => false, 'JSON string' => true]);

it('rejects display orders wider than their integer columns', function (string $field): void {
    $create = static function (string $key, int $order) use ($field): MetafieldDefinition {
        $attributes = [
            'namespace' => 'product', 'key' => $key, 'type' => 'string',
            'assignment' => ['ownerType' => 'products', 'section' => 'general'],
            'translations' => ['en' => ['title' => 'Order']],
        ];
        data_set($attributes, $field, $order);

        return app(MetafieldDefinitions::class)->create($attributes, SystemActor::Background);
    };

    expect(fn () => $create('wide_order', 2147483648))->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field))
        ->and($create('widest_order', 2147483647)->exists)->toBeTrue();
})->with(['displayOrder', 'assignment.displayOrder']);

it('stores defaults in the canonical form owner values use', function (): void {
    Gate::define('metafields.reference', static fn (SystemActor $actor): bool => true);
    config(['nvl-metafields.authorization.reference_ability' => 'metafields.reference']);
    $target = TestMetafieldOwner::query()->create(['name' => 'Target']);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $padded = ' '.$target->id.' ';

    $reference = hardeningDefinition('related', 'reference', ['referencedModelType' => 'products', 'defaultValue' => $padded]);
    $count = hardeningDefinition('count', 'integer', ['defaultValue' => '   ']);
    $stored = app(MetafieldValues::class)->set($owner, 'product.related', $padded, SystemActor::Background);

    expect($reference->getSerializableDefaultValue())->toBe((string) $target->id)
        ->and($reference->default_referenced_id)->toBe($stored->referenced_id)
        ->and($reference->getDefaultValue()?->getKey())->toBe($target->id)
        ->and($count->hasDefaultValue())->toBeFalse();
});

it('rejects text the database would truncate or refuse instead of storing part of it', function (string $text): void {
    hardeningDefinition('code', 'string');
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $copy = static fn (array $translation): MetafieldDefinition => app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'copy', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => $translation + ['title' => 'Copy']],
    ], SystemActor::Background);

    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.code', $text, SystemActor::Background))->toThrow(ValidationException::class)
        ->and(fn () => hardeningDefinition('code_default', 'string', ['defaultValue' => $text]))->toThrow(ValidationException::class)
        ->and(fn () => $copy(['title' => $text]))->toThrow(ValidationException::class)
        ->and(fn () => $copy(['description' => $text]))->toThrow(ValidationException::class)
        ->and(fn () => $copy(['hint' => $text]))->toThrow(ValidationException::class)
        ->and(Metafield::query()->count())->toBe(0)
        ->and(MetafieldDefinition::query()->count())->toBe(1);
})->with(['NUL byte' => ["a\0b"], 'invalid UTF-8' => ["a\xFFb"]]);
