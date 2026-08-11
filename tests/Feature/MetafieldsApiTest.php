<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Providers\RouteServiceProvider;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;

beforeEach(function (): void {
    config([
        'translatable.locales' => ['en', 'bg'],
        'translatable.fallback_locales' => ['en'],
        'metafields.routes.enabled' => true,
        'metafields.routes.middleware' => ['api'],
        'metafields.routes.management_middleware' => [],
        'metafields.owners' => [
            'products' => [
                'model' => TestMetafieldOwner::class,
                'label' => 'Products',
                'supported_types' => array_map(
                    static fn (MetafieldTypeEnum $type): string => $type->value,
                    MetafieldTypeEnum::cases(),
                ),
                'sections' => ['general', 'details'],
                'runtime_status' => 'live',
            ],
            'planned-users' => [
                'model' => User::class,
                'label' => 'Planned users',
                'supported_types' => [MetafieldTypeEnum::String->value],
                'sections' => ['general'],
                'runtime_status' => 'planned',
            ],
        ],
        'metafields.reference_models' => [
            'products' => TestMetafieldOwner::class,
        ],
    ]);

    app()->instance(
        MetafieldAuthorization::class,
        new class implements MetafieldAuthorization
        {
            public function authorizeDefinition(
                MetafieldAbility $ability,
                ?MetafieldDefinition $definition = null,
            ): void {}

            public function authorizeOwner(
                MetafieldAbility $ability,
                ?Model $owner = null,
                ?MetafieldDefinition $definition = null,
            ): void {}
        },
    );
    app()->instance(
        MetafieldReferenceAuthorization::class,
        new class implements MetafieldReferenceAuthorization
        {
            public function authorize(
                Model $owner,
                MetafieldDefinition $definition,
                Model $reference,
            ): void {}
        },
    );

    TestMetafieldOwner::query()->create(['name' => 'API owner']);
    (new RouteServiceProvider(app()))->map();
});

it('creates and patches definitions through separate revision-aware contracts', function (): void {
    $created = $this->postJson('/api/v1/metafields/definitions', [
        'namespace' => 'content',
        'key' => 'summary',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
        ],
        'translations' => [
            'en' => [
                'title' => 'Summary',
                'description' => 'Original description',
            ],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.definition.title', 'Summary');

    $definitionId = $created->json('data.id');
    $revision = $created->json('data.revision');

    $this->putJson("/api/v1/metafields/definitions/{$definitionId}", [
        'namespace' => 'content',
        'key' => 'summary',
        'type' => 'string',
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
        ],
        'translations' => [
            'en' => ['description' => 'Patched description'],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('expectedRevision');

    $this->putJson("/api/v1/metafields/definitions/{$definitionId}", [
        'namespace' => 'content',
        'key' => 'summary',
        'type' => 'string',
        'expectedRevision' => $revision,
        'assignment' => [
            'ownerType' => 'products',
            'section' => 'general',
        ],
        'translations' => [
            'en' => ['description' => 'Patched description'],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('data.definition.title', 'Summary')
        ->assertJsonPath('data.definition.description', 'Patched description');
});

it('exposes only live owner aliases without application model classes', function (): void {
    $this->getJson('/api/v1/metafields/owners')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'products')
        ->assertJsonMissing(['model' => TestMetafieldOwner::class]);

    $this->getJson('/api/v1/metafields/owners/planned-users/1')
        ->assertNotFound();
});

it('requires expected revisions when updating and deleting owner values', function (): void {
    $owner = TestMetafieldOwner::query()->sole();
    $definition = MetafieldDefinition::factory()->create([
        'namespace' => 'content',
        'key' => 'label',
    ]);
    MetafieldDefinitionAssignment::factory()
        ->forDefinition($definition)
        ->forOwnerType('products')
        ->create();

    $created = $this->putJson("/api/v1/metafields/owners/products/{$owner->getKey()}", [
        'items' => [[
            'definitionId' => $definition->id,
            'value' => 'First',
        ]],
    ])->assertSuccessful()
        ->assertJsonPath('data.meta.ownerType', 'products');

    $revision = $created->json('data.items.0.revision');

    $this->putJson("/api/v1/metafields/owners/products/{$owner->getKey()}", [
        'items' => [[
            'definitionId' => $definition->id,
            'value' => 'Blind overwrite',
        ]],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('items.0.expectedRevision');

    $updated = $this->putJson("/api/v1/metafields/owners/products/{$owner->getKey()}", [
        'items' => [[
            'definitionId' => $definition->id,
            'value' => 'Second',
            'expectedRevision' => $revision,
        ]],
    ])->assertSuccessful()
        ->assertJsonPath('data.items.0.value', 'Second');

    $this->deleteJson(
        "/api/v1/metafields/owners/products/{$owner->getKey()}/{$definition->id}",
    )->assertUnprocessable()
        ->assertJsonValidationErrors('expectedRevision');

    $this->deleteJson(
        "/api/v1/metafields/owners/products/{$owner->getKey()}/{$definition->id}",
        ['expectedRevision' => $updated->json('data.items.0.revision')],
    )->assertSuccessful()
        ->assertJsonPath('data.deleted', true);
});

it('serializes reference lists as identifiers without referenced model state', function (): void {
    $owner = TestMetafieldOwner::query()->sole();
    $targetOne = TestMetafieldOwner::query()->create(['name' => 'Sensitive one']);
    $targetTwo = TestMetafieldOwner::query()->create(['name' => 'Sensitive two']);
    $definition = MetafieldDefinition::factory()->ofType(MetafieldTypeEnum::ReferenceList)->create([
        'namespace' => 'content',
        'key' => 'related',
        'referenced_model_type' => 'products',
    ]);
    MetafieldDefinitionAssignment::factory()
        ->forDefinition($definition)
        ->forOwnerType('products')
        ->create();

    $response = $this->putJson("/api/v1/metafields/owners/products/{$owner->getKey()}", [
        'items' => [[
            'definitionId' => $definition->id,
            'value' => [(string) $targetOne->getKey(), (string) $targetTwo->getKey()],
        ]],
    ])->assertSuccessful()
        ->assertJsonPath('data.items.0.value', [
            (string) $targetOne->getKey(),
            (string) $targetTwo->getKey(),
        ]);

    expect($response->getContent())
        ->not->toContain('Sensitive one')
        ->not->toContain(TestMetafieldOwner::class);
});

it('renders authorization failures as forbidden API responses', function (): void {
    app()->instance(
        MetafieldAuthorization::class,
        new class implements MetafieldAuthorization
        {
            public function authorizeDefinition(
                MetafieldAbility $ability,
                ?MetafieldDefinition $definition = null,
            ): void {
                throw new AuthorizationException('Denied.');
            }

            public function authorizeOwner(
                MetafieldAbility $ability,
                ?Model $owner = null,
                ?MetafieldDefinition $definition = null,
            ): void {
                throw new AuthorizationException('Denied.');
            }
        },
    );

    $this->getJson('/api/v1/metafields/definitions')->assertForbidden();
});
