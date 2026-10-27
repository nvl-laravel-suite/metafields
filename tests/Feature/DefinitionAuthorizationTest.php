<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\ConfiguredMetafieldAuthorization;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldReader;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldActor;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    foreach (['create', 'read', 'update', 'archive', 'delete'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (Authenticatable|SystemActor $actor): bool => true);
    }
    config([
        'nvl-translatable.locales' => ['en'],
        'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']]],
    ]);
});

it('enforces the definition policy with fresh actors and canonical mutation targets', function (string $operation, MetafieldAbility $ability): void {
    $attributes = [
        'namespace' => 'product', 'key' => 'headline', 'type' => 'string',
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Headline']],
    ];
    $definition = app(MetafieldDefinitions::class)->create($attributes, SystemActor::Background);
    $original = $definition->getAttributes();
    $actor = TestMetafieldActor::query()->create(['name' => 'Canonical actor']);
    $actor->name = 'Forged actor';
    $definition->namespace = 'forged';
    $definition->revision = 999;
    $definition->setRelation('assignments', collect());

    $authorization = new class($actor, $definition->id, $ability) implements MetafieldAuthorization
    {
        public bool $called = false;

        public function __construct(
            private readonly TestMetafieldActor $carriedActor,
            private readonly string $definitionId,
            private readonly MetafieldAbility $expectedAbility,
        ) {}

        public function authorizeDefinition(Authenticatable|SystemActor $actor, MetafieldAbility $ability, ?MetafieldDefinition $definition = null): void
        {
            $this->called = true;
            expect($actor)->toBeInstanceOf(TestMetafieldActor::class)
                ->not->toBe($this->carriedActor)
                ->and($actor->name)->toBe('Canonical actor')
                ->and($ability)->toBe($this->expectedAbility);
            if (in_array($ability, [MetafieldAbility::CreateDefinition, MetafieldAbility::ListDefinitions], true)) {
                expect($definition)->toBeNull();
            } else {
                expect($definition)->toBeInstanceOf(MetafieldDefinition::class)
                    ->and($definition->id)->toBe($this->definitionId)
                    ->and($definition->namespace)->toBe('product')
                    ->and($definition->revision)->toBe(1)
                    ->and($definition->getRelations())->toBe([]);
            }

            throw new AuthorizationException('Fine definition policy denied.');
        }

        public function authorizeOwner(Authenticatable|SystemActor $actor, MetafieldAbility $ability, ?Model $owner = null, ?MetafieldDefinition $definition = null): void {}
    };
    app()->instance(MetafieldAuthorization::class, $authorization);

    $call = match ($operation) {
        'create' => fn () => app(MetafieldDefinitions::class)->create([...$attributes, 'key' => 'other'], $actor),
        'list' => fn () => app(MetafieldReader::class)->definitions($actor),
        'update' => fn () => app(MetafieldDefinitions::class)->update($definition, ['displayOrder' => 5, 'translations' => ['en' => ['title' => 'Changed']]], 1, $actor),
        'archive' => fn () => app(MetafieldDefinitions::class)->archive($definition, true, 1, $actor),
        'delete' => fn () => app(MetafieldDefinitions::class)->delete($definition, 1, $actor),
    };
    expect($call)->toThrow(AuthorizationException::class, 'Fine definition policy denied.');
    expect($authorization->called)->toBeTrue()
        ->and(MetafieldDefinition::query()->count())->toBe(1)
        ->and($definition->fresh()->getAttributes())->toBe($original)
        ->and($definition->fresh()->assignments)->toHaveCount(1)
        ->and($definition->fresh()->translations->first()->title)->toBe('Headline');
})->with([
    'create' => ['create', MetafieldAbility::CreateDefinition],
    'list' => ['list', MetafieldAbility::ListDefinitions],
    'update' => ['update', MetafieldAbility::UpdateDefinition],
    'archive' => ['archive', MetafieldAbility::UpdateDefinition],
    'delete' => ['delete', MetafieldAbility::DeleteDefinition],
]);

it('requires the coarse actor gate before invoking a custom definition policy', function (): void {
    Gate::define('metafields.read', static fn (Authenticatable|SystemActor $actor): bool => false);
    $policy = Mockery::mock(MetafieldAuthorization::class);
    $policy->shouldNotReceive('authorizeDefinition');
    app()->instance(MetafieldAuthorization::class, $policy);

    expect(fn () => app(MetafieldReader::class)->definitions(SystemActor::Background))
        ->toThrow(AuthorizationException::class);
});

it('honors the configured definition gate instead of a permissive native fallback', function (): void {
    config(['nvl-metafields.authorization.definition_ability' => 'custom.definition']);
    $definition = MetafieldDefinition::factory()->create(['namespace' => 'product', 'key' => 'headline']);
    $definition->namespace = 'forged';
    $seen = [];
    Gate::define('custom.definition', static function (SystemActor $actor, string $ability, MetafieldDefinition $target) use (&$seen): bool {
        $seen = [$actor, $ability, $target->namespace];

        return false;
    });

    expect(fn () => app(MetafieldDefinitions::class)->archive($definition, true, 1, SystemActor::Background))
        ->toThrow(AuthorizationException::class);
    expect($seen)->toBe([SystemActor::Background, MetafieldAbility::UpdateDefinition->value, 'product'])
        ->and($definition->fresh()->archived_at)->toBeNull();

    Gate::define('metafields.update', static fn (SystemActor $actor): bool => false);
    Gate::define('custom.definition', static fn (SystemActor $actor, string $ability, MetafieldDefinition $target): bool => $ability === MetafieldAbility::UpdateDefinition->value && $target->namespace === 'product');
    app(ConfiguredMetafieldAuthorization::class)->authorizeDefinition(SystemActor::Background, MetafieldAbility::UpdateDefinition, $definition->fresh());
});

it('uses native definition gates as the default and denies undefined or malformed configuration', function (): void {
    $authorization = app(ConfiguredMetafieldAuthorization::class);
    $definition = MetafieldDefinition::factory()->create();
    $seen = null;
    Gate::define('metafields.update', static function (SystemActor $actor, MetafieldDefinition $target) use (&$seen): bool {
        $seen = $target->id;

        return true;
    });
    $authorization->authorizeDefinition(SystemActor::Background, MetafieldAbility::UpdateDefinition, $definition);
    expect($seen)->toBe($definition->id);

    Gate::define('metafields.update', static fn (SystemActor $actor): bool => false);
    expect(fn () => $authorization->authorizeDefinition(SystemActor::Background, MetafieldAbility::UpdateDefinition, $definition))
        ->toThrow(AuthorizationException::class);
    Gate::define('metafields.update', static fn (SystemActor $actor): bool => true);
    config(['nvl-metafields.authorization.definition_ability' => 'undefined.definition']);
    expect(fn () => $authorization->authorizeDefinition(SystemActor::Background, MetafieldAbility::UpdateDefinition, $definition))
        ->toThrow(AuthorizationException::class);
    config(['nvl-metafields.authorization.definition_ability' => false]);
    expect(fn () => $authorization->authorizeDefinition(SystemActor::Background, MetafieldAbility::UpdateDefinition, $definition))
        ->toThrow(AuthorizationException::class);
    config(['nvl-metafields.authorization.definition_ability' => null]);
    expect(fn () => $authorization->authorizeDefinition(SystemActor::Background, MetafieldAbility::MutateOwner, $definition))
        ->toThrow(AuthorizationException::class);
});
