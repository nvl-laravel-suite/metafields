<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;

beforeEach(function (): void {
    $this->originalOwnerMorphMap = Relation::morphMap();
});

afterEach(function (): void {
    Relation::morphMap($this->originalOwnerMorphMap, false);
});

it('keeps metafield behavior when the owner model is a shared alias reference', function (): void {
    config()->set('nvl-core.owners', ['article' => TestMetafieldOwner::class]);
    config()->set('metafields.owners', ['article' => [
        'label' => 'Articles',
        'sections' => ['content'],
        'runtime_status' => 'planned',
    ]]);
    $registry = app(MetafieldOwnerRegistry::class);

    expect($registry->configurationForType('article')['model'])->toBe(TestMetafieldOwner::class)
        ->and($registry->forType('article')->sections)->toBe(['content'])
        ->and($registry->supportsRuntimeEditing('article'))->toBeFalse()
        ->and(fn () => $registry->configurationForType('unlisted'))->toThrow(InvalidArgumentException::class);
});
