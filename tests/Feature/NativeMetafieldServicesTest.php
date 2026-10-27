<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldReader;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldActor;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Translatable\Enums\TranslationSyncMode;

beforeEach(function (): void {
    foreach (['create', 'update', 'archive', 'delete', 'grant', 'revoke', 'import', 'write', 'read'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    }
    config([
        'nvl-translatable.locales' => ['en', 'bg'],
        'nvl-translatable.fallback_locales' => ['en'],
        'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']]],
    ]);
});

it('keeps all resources unscoped and captures ordinary queued work without Ownership', function (): void {
    config(['nvl-ownership.enabled' => false]);
    $resources = app(OwnershipResourceRegistry::class)->all();

    expect($resources)->toHaveKey('metafields.catalog-grants');
    foreach ($resources as $resource) {
        expect($resource->ownership->strategy)->toBe('unscoped')
            ->and($resource->immutableFields)->toBe([]);
    }

    $queue = app(OwnershipQueueContext::class);
    expect($queue)->toBeInstanceOf(DisabledOwnershipQueueContext::class);
    $envelope = $queue->captureUnowned();

    expect($envelope->context()->mode)->toBe(OwnershipContextMode::Disabled)
        ->and($queue->run($envelope, static fn (): string => 'ordinary work'))->toBe('ordinary work');
});

it('creates a registered definition and stores a typed native value', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product',
        'key' => 'headline',
        'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Headline']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    $value = app(MetafieldValues::class)->set($owner, 'product.headline', 'Hello', SystemActor::Background);

    expect($definition->handle)->toBe('product.headline')
        ->and($value)->toBeInstanceOf(Metafield::class)
        ->and($value->getValue())->toBe('Hello')
        ->and(app(MetafieldReader::class)->definitions(SystemActor::Background))->toHaveCount(1);
});

it('rolls back an entire mixed-validity sync and keeps the first value', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $first = $definitions->create([
        'namespace' => 'product', 'key' => 'headline', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Headline']],
    ], SystemActor::Background);
    $second = $definitions->create([
        'namespace' => 'product', 'key' => 'count', 'type' => 'integer',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Count']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);

    expect(fn () => app(MetafieldValues::class)->sync($owner, [
        ['definitionId' => $first->id, 'value' => 'Valid'],
        ['definitionId' => $second->id, 'value' => 'not-an-integer'],
    ], SystemActor::Background))->toThrow(ValidationException::class);
    expect(Metafield::query()->count())->toBe(0);
});

it('rejects unsupported localized types and unregistered reference targets', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $base = [
        'namespace' => 'product', 'key' => 'invalid', 'type' => 'integer',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Invalid']],
    ];
    expect(fn () => $definitions->create([...$base, 'isTranslatable' => true], SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect(fn () => $definitions->create([...$base, 'type' => 'reference', 'referencedModelType' => 'unregistered'], SystemActor::Background))
        ->toThrow(ValidationException::class);
});

it('distinguishes omitted localized values from explicit null and preserves accepted locale rows', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'caption', 'type' => 'string', 'isTranslatable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Caption']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    $values = app(MetafieldValues::class);
    $original = $values->set($owner, 'product.caption', 'English', SystemActor::Background, 'en');
    $updated = $values->sync($owner, [[
        'definitionId' => $definition->id,
        'translations' => ['bg' => 'Български'],
        'expected_revision' => $original->revision,
    ]], SystemActor::Background)->first();
    expect($updated->translations->pluck('value', 'locale')->sortKeys()->all())->toBe(['bg' => 'Български', 'en' => 'English']);
    expect(fn () => $values->sync($owner, [[
        'definitionId' => $definition->id,
        'translations' => ['en' => null],
        'expected_revision' => $updated->revision,
    ]], SystemActor::Background))->toThrow(ValidationException::class);
    expect($updated->fresh()->translations->pluck('value', 'locale')->sortKeys()->all())->toBe(['bg' => 'Български', 'en' => 'English']);
});

it('requires an explicit granted background capability and rejects unknown mutation keys', function (): void {
    Gate::define('metafields.create', static fn (SystemActor $actor): bool => false);
    expect(fn () => app(MetafieldDefinitions::class)->create([], SystemActor::Background))
        ->toThrow(AuthorizationException::class);

    Gate::define('metafields.write', static fn (SystemActor $actor): bool => true);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    expect(fn () => app(MetafieldValues::class)->sync($owner, [['definitionId' => '01900000-0000-7000-8000-000000000001', 'tenant_id' => 'forged']], SystemActor::Background))
        ->toThrow(ValidationException::class);
});

it('archives a definition at the expected revision and permits a new active handle', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $attributes = [
        'namespace' => 'product', 'key' => 'summary', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Summary']],
    ];
    $first = $definitions->create($attributes, SystemActor::Background);
    $archived = $definitions->archive($first, true, $first->revision, SystemActor::Background);
    $replacement = $definitions->create($attributes, SystemActor::Background);
    expect($archived->archived_at)->not->toBeNull()
        ->and($replacement->id)->not->toBe($first->id)
        ->and($replacement->active_handle)->toBe('product.summary');
});

it('validates each heterogeneous item using its registered type and rolls back earlier writes', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $string = $definitions->create([
        'namespace' => 'product', 'key' => 'label', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Label']],
    ], SystemActor::Background);
    $url = $definitions->create([
        'namespace' => 'product', 'key' => 'link', 'type' => 'url',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Link']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    expect(fn () => app(MetafieldValues::class)->sync($owner, [
        ['definitionId' => $string->id, 'value' => 'Valid'],
        ['definitionId' => $url->id, 'value' => 'javascript:alert(1)'],
    ], SystemActor::Background))->toThrow(ValidationException::class);
    expect(Metafield::query()->count())->toBe(0);
    $saved = app(MetafieldValues::class)->sync($owner, [
        ['definitionId' => $string->id, 'value' => 'Valid'],
        ['definitionId' => $url->id, 'value' => 'https://example.com'],
    ], SystemActor::Background);
    expect($saved->mapWithKeys(fn (Metafield $field): array => [$field->definition->handle => $field->getValue()])->all())
        ->toBe(['product.label' => 'Valid', 'product.link' => 'https://example.com']);
});

it('preserves definition patches and rejects stale or invalid default changes', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $definition = $definitions->create([
        'namespace' => 'product', 'key' => 'rating', 'type' => 'integer', 'defaultValue' => 5,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Rating', 'hint' => 'Original']],
    ], SystemActor::Background);
    $updated = $definitions->update($definition, [
        'translations' => ['en' => ['hint' => 'Changed']],
    ], $definition->revision, SystemActor::Background);
    expect($updated->translations->first()->title)->toBe('Rating')
        ->and($updated->translations->first()->hint)->toBe('Changed')
        ->and($updated->getSerializableDefaultValue())->toBe(5);
    expect(fn () => $definitions->update($updated, ['validationRules' => ['min:6']], $definition->revision, SystemActor::Background))
        ->toThrow(StaleMetafieldVersionException::class);
    expect(fn () => $definitions->update($updated, ['validationRules' => ['min:6']], $updated->revision, SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect($updated->fresh()->getSerializableDefaultValue())->toBe(5);
});

it('requires registered owner assignments and all required fields in an affected section', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    expect(fn () => $definitions->create([
        'namespace' => 'product', 'key' => 'wrong', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'unknown'],
        'translations' => ['en' => ['title' => 'Wrong']],
    ], SystemActor::Background))->toThrow(ValidationException::class);
    $required = $definitions->create([
        'namespace' => 'product', 'key' => 'required', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general', 'isRequired' => true],
        'translations' => ['en' => ['title' => 'Required']],
    ], SystemActor::Background);
    $optional = $definitions->create([
        'namespace' => 'product', 'key' => 'optional', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Optional']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    expect(fn () => app(MetafieldValues::class)->sync($owner, [['definitionId' => $optional->id, 'value' => 'Optional']], SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect(Metafield::query()->count())->toBe(0);
    app(MetafieldValues::class)->sync($owner, [['definitionId' => $required->id, 'value' => 'Present']], SystemActor::Background);
    expect(app(MetafieldValues::class)->set($owner, 'product.optional', 'Optional', SystemActor::Background)->getValue())->toBe('Optional');
});

it('authorizes every reference and rejects missing targets before persistence', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'related', 'type' => 'reference', 'referencedModelType' => 'products',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Related']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $target = TestMetafieldOwner::query()->create(['name' => 'Target']);
    expect($definition->referenced_model_type)->toBe('products');
    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.related', (string) $target->id, SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect(Metafield::query()->count())->toBe(0);
    config(['nvl-metafields.authorization.reference_ability' => 'metafields.reference']);
    Gate::define('metafields.reference', static fn (SystemActor $actor): bool => false);
    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.related', (string) $target->id, SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    Gate::define('metafields.reference', static fn (SystemActor $actor): bool => true);
    expect(app(MetafieldValues::class)->set($owner, 'product.related', (string) $target->id, SystemActor::Background)->referenced_id)->toBe((string) $target->id);
    expect(fn () => app(MetafieldValues::class)->set($owner, 'product.related', '999999', SystemActor::Background))
        ->toThrow(ValidationException::class);
});

it('requires explicit cascade when deleting a definition with live owner values', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $definition = $definitions->create([
        'namespace' => 'product', 'key' => 'tagline', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Tagline']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    app(MetafieldValues::class)->set($owner, 'product.tagline', 'Live', SystemActor::Background);
    expect(fn () => $definitions->delete($definition, $definition->revision, SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect($definitions->delete($definition, $definition->revision, SystemActor::Background, true))->toBeTrue()
        ->and(Metafield::query()->count())->toBe(0)
        ->and(MetafieldDefinition::query()->count())->toBe(0);
});

it('patches a reference definition without dropping its registered target alias', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $definition = $definitions->create([
        'namespace' => 'product', 'key' => 'related', 'type' => 'reference', 'referencedModelType' => 'products',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Related']],
    ], SystemActor::Background);
    $updated = $definitions->update($definition, ['translations' => ['en' => ['hint' => 'Select a product']]], $definition->revision, SystemActor::Background);
    expect($updated->referenced_model_type)->toBe('products')
        ->and($updated->translations->first()->hint)->toBe('Select a product');
});

it('rejects invalid localized defaults before creating the definition', function (): void {
    expect(fn () => app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'caption', 'type' => 'string', 'isTranslatable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Caption', 'defaultValue' => ['not', 'a', 'string']]],
    ], SystemActor::Background))->toThrow(ValidationException::class);
    expect(MetafieldDefinition::query()->count())->toBe(0);
});

it('patches and replaces localized values while advancing revisions', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'caption', 'type' => 'string', 'isTranslatable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Caption']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $values = app(MetafieldValues::class);
    $first = $values->set($owner, 'product.caption', 'English', SystemActor::Background, 'en');
    $patched = $values->sync($owner, [[
        'definitionId' => $definition->id, 'translations' => ['bg' => 'Български'],
        'expected_revision' => $first->revision, 'translation_mode' => TranslationSyncMode::Patch,
    ]], SystemActor::Background)->first();
    expect($patched->revision)->toBe($first->revision + 1)
        ->and($patched->translations)->toHaveCount(2);
    $replaced = $values->sync($owner, [[
        'definitionId' => $definition->id, 'translations' => ['en' => 'Updated'],
        'expected_revision' => $patched->revision, 'translation_mode' => TranslationSyncMode::Replace,
    ]], SystemActor::Background)->first();
    expect($replaced->revision)->toBe($patched->revision + 1)
        ->and($replaced->translations->pluck('value', 'locale')->all())->toBe(['en' => 'Updated']);
});

it('clears and restores values with explicit revisions', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'label', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Label']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $values = app(MetafieldValues::class);
    $first = $values->set($owner, 'product.label', 'First', SystemActor::Background);
    expect($first->revision)->toBe(1)
        ->and($values->delete($owner, $definition, $first->revision, SystemActor::Background))->toBeTrue()
        ->and(Metafield::query()->count())->toBe(0);
    $restored = $values->set($owner, 'product.label', 'Second', SystemActor::Background);
    expect($restored->id)->toBe($first->id)
        ->and($restored->getValue())->toBe('Second');
});

it('returns native field models only after explicit owner read authorization', function (): void {
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'label', 'type' => 'string', 'defaultValue' => 'Fallback',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Label']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    expect(fn () => app(MetafieldReader::class)->forOwner($owner, SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    config(['nvl-metafields.authorization.owner_ability' => 'metafields.owner']);
    Gate::define('metafields.owner', static fn (SystemActor $actor): bool => true);
    $fields = app(MetafieldReader::class)->forOwner($owner, SystemActor::Background);
    expect($fields)->toHaveCount(1)
        ->and($fields->first()['definition']->id)->toBe($definition->id)
        ->and($fields->first()['value'])->toBeNull();
    $archived = app(MetafieldDefinitions::class)->archive($definition, true, $definition->revision, SystemActor::Background);
    expect($archived->archived_at)->not->toBeNull()
        ->and(app(MetafieldReader::class)->forOwner($owner, SystemActor::Background))->toHaveCount(0);
});

it('reloads the persisted actor through its canonical model and rejects mutated identity', function (): void {
    Gate::define('metafields.read', static fn (TestMetafieldActor $current): bool => $current->name === 'Persisted');
    $actor = TestMetafieldActor::query()->create(['name' => 'Persisted']);
    $other = TestMetafieldActor::query()->create(['name' => 'Other']);
    $actor->name = 'Forged';
    expect(app(MetafieldReader::class)->definitions($actor))->toHaveCount(0);
    $actor->setTable('test_metafield_owners');
    expect(fn () => app(MetafieldReader::class)->definitions($actor))
        ->toThrow(AuthorizationException::class);
    $actor->setTable('test_metafield_actors');
    $actor->setConnection('other');
    expect(fn () => app(MetafieldReader::class)->definitions($actor))
        ->toThrow(AuthorizationException::class);
    $actor->setConnection(null);
    $actor->useKeyColumn('name');
    expect(fn () => app(MetafieldReader::class)->definitions($actor))
        ->toThrow(AuthorizationException::class);
    $actor->useKeyColumn('id');
    $actor->id = $other->id;
    expect(fn () => app(MetafieldReader::class)->definitions($actor))
        ->toThrow(AuthorizationException::class);
});

it('rejects explicit null for required definition fields instead of treating it as omission', function (string $field): void {
    $definitions = app(MetafieldDefinitions::class);
    $definition = $definitions->create([
        'namespace' => 'product', 'key' => 'required-null', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Retained title']],
    ], SystemActor::Background);

    expect(fn () => $definitions->update($definition, [$field => null], $definition->revision, SystemActor::Background))
        ->toThrow(ValidationException::class);
    expect($definition->fresh()->revision)->toBe($definition->revision);
})->with(['namespace', 'key', 'type', 'isTranslatable']);

it('normalizes accepted clear flags and revision values before applying a value sync', function (): void {
    $definitions = app(MetafieldDefinitions::class);
    $definition = $definitions->create([
        'namespace' => 'product', 'key' => 'clear-normalized', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Clear value']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Product']);
    $values = app(MetafieldValues::class);
    $value = $values->set($owner, $definition->handle, 'Stored value', SystemActor::Background);

    $result = $values->sync($owner, [[
        'definitionId' => $definition->id,
        'clear' => '1',
        'expected_revision' => (string) $value->revision,
    ]], SystemActor::Background);

    expect($result)->toBeEmpty()
        ->and(Metafield::query()->whereKey($value->id)->exists())->toBeFalse();
});
