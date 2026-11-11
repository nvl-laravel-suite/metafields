<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Tests\Fixtures\CountingMetafieldHost;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwnerBase;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwnerChild;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

beforeEach(function (): void {
    config([
        'nvl-metafields.owners' => [
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

    expect($owner['type'])->toBe('articles')
        ->and($owner['runtimeStatus'])->toBe('live')
        ->and($owner['supportsRuntimeEditing'])->toBeTrue()
        ->and($owner['sections'])->toBe(['content']);
});

it('rejects ambiguous aliases for the same owner model', function (): void {
    config()->set('nvl-metafields.owners.archives.model', TestMetafieldOwner::class);

    expect(fn () => app(MetafieldOwnerRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'already registered');
});

it('keeps capability aliases independent of a host owned morph-map entry', function (): void {
    $existingMorphMap = Relation::morphMap();

    try {
        Relation::morphMap(['articles' => User::class], false);

        expect(app(MetafieldOwnerRegistry::class)->all()['articles']['model'])->toBe(TestMetafieldOwner::class)
            ->and(Relation::morphMap()['articles'])->toBe(User::class);
    } finally {
        Relation::morphMap($existingMorphMap, false);
    }
});

it('rejects inheritance-ambiguous owner registrations', function (): void {
    config()->set('nvl-metafields.owners.articles.model', TestMetafieldOwnerBase::class);
    config()->set('nvl-metafields.owners.archives.model', TestMetafieldOwnerChild::class);

    expect(fn () => app(MetafieldOwnerRegistry::class)->all())
        ->toThrow(InvalidArgumentException::class, 'inheritance makes owner resolution ambiguous');
});

it('requires one stable string alias per reference model', function (): void {
    config()->set('nvl-metafields.reference_models', [
        TestMetafieldOwner::class,
    ]);

    expect(fn () => MetafieldReferenceModelRegistry::all())
        ->toThrow(InvalidArgumentException::class, 'stable string alias');

    config()->set('nvl-metafields.reference_models', [
        'alternate-articles' => TestMetafieldOwner::class,
    ]);

    expect(fn () => MetafieldReferenceModelRegistry::all())
        ->toThrow(InvalidArgumentException::class, 'already registered as [articles]');
});

it('reports invalid reference aliases through the doctor', function (): void {
    config()->set('nvl-metafields.reference_models', [
        TestMetafieldOwner::class,
    ]);

    $check = collect(app(MetafieldDoctor::class)->inspect())
        ->firstWhere('key', 'registry.references');

    expect($check['passed'])->toBeFalse()
        ->and($check['message'])->toContain('stable string alias');
});

it('marks definition-only owner surfaces as planned', function (): void {
    $owner = app(MetafieldOwnerRegistry::class)->forType('archives');

    expect($owner['runtimeStatus'])->toBe('planned')
        ->and($owner['supportsRuntimeEditing'])->toBeFalse();
});

it('reads each host ownership declaration once per registry state and again when the registry changes', function (): void {
    config(['nvl-metafields.owners' => ['counted' => ['model' => CountingMetafieldHost::class, 'label' => 'Counted']]]);
    CountingMetafieldHost::$declarationReads = 0;

    expect(MetafieldOwnership::declared())->toBeFalse()
        ->and(MetafieldOwnership::declared())->toBeFalse()
        ->and(MetafieldOwnership::active())->toBeFalse()
        ->and(CountingMetafieldHost::$declarationReads)->toBe(1);

    config(['nvl-metafields.owners.counted.label' => 'Recounted']);

    expect(MetafieldOwnership::declared())->toBeFalse()
        ->and(CountingMetafieldHost::$declarationReads)->toBe(2)
        ->and(app(MetafieldOwnerRegistry::class)->forType('counted')['label'])->toBe('Recounted');
});

it('recomputes normalized owners when configuration or code registrations change', function (): void {
    $registry = app(MetafieldOwnerRegistry::class);

    expect(array_keys($registry->all()))->toBe(['articles', 'archives'])
        ->and($registry->all())->toBe($registry->all());

    config(['nvl-metafields.owners.archives.runtime_status' => 'live']);
    expect($registry->supportsRuntimeEditing('archives'))->toBeTrue();

    config(['nvl-metafields.owners' => ['bases' => ['model' => TestMetafieldOwnerBase::class, 'label' => 'Bases']]]);
    $registry->register('children', TestMetafieldOwnerChild::class, 'Children');
    expect(fn () => $registry->all())->toThrow(InvalidArgumentException::class, 'ambiguous');

    config(['nvl-metafields.owners' => []]);
    expect(array_keys($registry->all()))->toBe(['children']);
});

it('refuses an owned host as soon as it joins an unowned registry', function (): void {
    expect(MetafieldOwnership::declared())->toBeFalse();

    config(['nvl-metafields.owners.owned' => ['model' => OwnershipMetafieldOwner::class, 'label' => 'Owned']]);

    expect(fn () => MetafieldOwnership::declared())->toThrow(OwnershipConfigurationInvalid::class);
});
