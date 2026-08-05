<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwnerBase;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwnerChild;

beforeEach(function (): void {
    config([
        'metafields.owners' => [
            'articles' => [
                'model' => TestMetafieldOwner::class,
                'label' => 'Articles',
                'supported_types' => [MetafieldTypeEnum::String->value],
                'sections' => ['content'],
                'runtime_status' => 'live',
            ],
            'archives' => [
                'model' => User::class,
                'label' => 'Archives',
                'supported_types' => [MetafieldTypeEnum::String->value],
                'sections' => ['metadata'],
                'runtime_status' => 'planned',
            ],
        ],
    ]);
});

it('supports arbitrary application-defined owner aliases', function (): void {
    $owner = app(MetafieldOwnerRegistry::class)->forType('articles');

    expect($owner->type)->toBe('articles')
        ->and($owner->runtimeStatus)->toBe('live')
        ->and($owner->supportsRuntimeEditing)->toBeTrue()
        ->and($owner->sections)->toBe(['content']);
});

it('rejects ambiguous aliases for the same owner model', function (): void {
    config()->set('metafields.owners.archives.model', TestMetafieldOwner::class);

    expect(fn () => app(MetafieldOwnerRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

it('rejects application morph-map conflicts before changing the global map', function (): void {
    $existingMorphMap = Relation::morphMap();

    try {
        Relation::morphMap(['articles' => User::class], false);

        expect(fn () => app(MetafieldOwnerRegistry::class)->all())
            ->toThrow(InvalidArgumentException::class, 'conflicts with the existing morph-map model')
            ->and(Relation::morphMap()['articles'])->toBe(User::class);
    } finally {
        Relation::morphMap($existingMorphMap, false);
    }
});

it('rejects inheritance-ambiguous owner registrations', function (): void {
    config()->set('metafields.owners.articles.model', TestMetafieldOwnerBase::class);
    config()->set('metafields.owners.archives.model', TestMetafieldOwnerChild::class);

    expect(fn () => app(MetafieldOwnerRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'inheritance makes owner resolution ambiguous');
});

it('requires one stable string alias per reference model', function (): void {
    config()->set('metafields.reference_models', [
        TestMetafieldOwner::class,
    ]);

    expect(fn () => MetafieldReferenceModelRegistry::all())
        ->toThrow(InvalidArgumentException::class, 'stable string alias');

    config()->set('metafields.reference_models', [
        'alternate-articles' => TestMetafieldOwner::class,
    ]);

    expect(fn () => MetafieldReferenceModelRegistry::all())
        ->toThrow(InvalidArgumentException::class, 'already registered as [articles]');
});

it('reports invalid reference aliases through the doctor', function (): void {
    config()->set('metafields.reference_models', [
        TestMetafieldOwner::class,
    ]);

    $check = collect(app(MetafieldDoctor::class)->inspect())
        ->firstWhere('key', 'registry.references');

    expect($check?->passed)->toBeFalse()
        ->and($check?->message)->toContain('stable string alias');
});

it('marks definition-only owner surfaces as planned', function (): void {
    $owner = app(MetafieldOwnerRegistry::class)->forType('archives');

    expect($owner->runtimeStatus)->toBe('planned')
        ->and($owner->supportsRuntimeEditing)->toBeFalse();
});
