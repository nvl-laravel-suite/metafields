<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Metafields\Data\MetafieldDefinitionAssignmentPayload;
use Nvl\Metafields\Data\MetafieldDefinitionSettings;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Translatable\Services\TranslationWriter;

test('metafield controllers remain final and delegate persistence queries', function (): void {
    $filesystem = new Filesystem;
    $controllerFiles = $filesystem->allFiles(__DIR__.'/../../src/Http/Controllers');

    foreach ($controllerFiles as $controllerFile) {
        $contents = $filesystem->get($controllerFile->getPathname());

        expect($contents)
            ->toMatch('/final class [A-Za-z0-9_]+Controller extends /')
            ->and($contents)->not->toContain('::query()');
    }
});

test('non-translatable metafield values remain raw and typed', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'type' => MetafieldTypeEnum::String,
        'is_translatable' => false,
    ]);
    $metafield = Metafield::query()->create([
        'definition_id' => $definition->id,
        'metafieldable_id' => (string) Str::uuid(),
        'metafieldable_type' => 'test-owner',
        'value' => 'red',
    ])->load('definition');

    expect($metafield->getRawOriginal('value'))->toBe('red')
        ->and($metafield->value)->toBe('red')
        ->and($metafield->getValue())->toBe('red');
});

test('metafield values and definitions resolve through the shared translation contract', function (): void {
    config()->set('translatable.locales', ['en', 'bg']);
    config()->set('translatable.fallback_locales', ['en']);

    $definition = MetafieldDefinition::factory()->translatable()->create([
        'type' => MetafieldTypeEnum::String,
    ]);
    $metafield = Metafield::query()->create([
        'definition_id' => $definition->id,
        'metafieldable_id' => (string) Str::uuid(),
        'metafieldable_type' => 'test-owner',
    ])->load('definition');
    $writer = app(TranslationWriter::class);

    $writer->patch($definition, [
        'en' => ['title' => 'Color'],
        'bg' => ['title' => 'Цвят'],
    ]);
    $writer->patch($metafield, [
        'en' => ['value' => 'red'],
        'bg' => ['value' => 'червено'],
    ]);

    expect(Schema::hasColumn('metafields_definitions', 'title'))->toBeFalse()
        ->and(Schema::hasColumn('metafields_definitions', 'description'))->toBeFalse()
        ->and(Schema::hasColumn('metafields_definitions', 'hint'))->toBeFalse()
        ->and($definition->displayTitle('bg'))->toBe('Цвят')
        ->and($metafield->getValue('bg'))->toBe('червено')
        ->and($metafield->getValue('en'))->toBe('red');
});

test('definition settings expose assignment data through the typed payload contract', function (): void {
    $definition = MetafieldDefinition::factory()->create();
    $assignment = MetafieldDefinitionAssignment::factory()
        ->forDefinition($definition)
        ->forOwnerType('products')
        ->create();

    $settings = MetafieldDefinitionSettings::fromModel($definition);

    expect($settings->assignment)
        ->toBeInstanceOf(MetafieldDefinitionAssignmentPayload::class)
        ->and($settings->assignment->definitionId)->toBe($definition->id)
        ->and($settings->assignment->ownerType)->toBe('products')
        ->and($settings->assignment->displayOrder)->toBe($assignment->display_order);
});

test('the metafield factory is standalone and can be overridden with a real owner', function (): void {
    $definition = MetafieldDefinition::factory()->create();
    $metafield = Metafield::factory()->forDefinition($definition)->create();

    expect($metafield->metafieldable_id)->toBeString()->toMatch('/^.+$/')
        ->and($metafield->metafieldable_type)->toBe(Model::class);
});

test('definition namespace scope is available across supported Laravel versions', function (): void {
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'catalog',
    ]);

    expect(MetafieldDefinition::query()->inNamespace('catalog')->pluck('id')->all())
        ->toBe([$definition->id]);
});
