<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Metafields\Traits\HasMetafields;

it('supports integer owner keys across relation reads writes and existence queries', function (): void {
    $owner = TestMetafieldOwner::query()->create(['name' => 'Primary owner']);
    $emptyOwner = TestMetafieldOwner::query()->create(['name' => 'Empty owner']);
    $definition = MetafieldDefinition::factory()->create();

    $metafield = $owner->metafields()
        ->withAttributes(['referenced_id' => 'pending-reference'])
        ->create([
            'definition_id' => $definition->id,
            'value' => 'Stored value',
        ]);

    expect($metafield->metafieldable_id)->toBe((string) $owner->getKey())
        ->and($metafield->metafieldable_type)->toBe($owner->getMorphClass())
        ->and($metafield->referenced_id)->toBe('pending-reference')
        ->and($owner->fresh()?->metafields)->toHaveCount(1)
        ->and(TestMetafieldOwner::query()->with('metafields')->findOrFail($owner->getKey())->metafields)
        ->toHaveCount(1)
        ->and(TestMetafieldOwner::query()->with('metafields')->findOrFail($emptyOwner->getKey())->metafields)
        ->toBeEmpty()
        ->and(TestMetafieldOwner::query()->whereHas('metafields')->pluck('id')->all())
        ->toBe([$owner->getKey()]);
});

it('handles absent keys and rejects compound owner identifiers', function (): void {
    $unsaved = new TestMetafieldOwner;

    expect($unsaved->metafields()->toSql())->toBeString();

    $compoundOwner = new class extends Model
    {
        use HasMetafields;

        public function getAttribute($key): mixed
        {
            return $key === $this->getKeyName()
                ? ['compound']
                : parent::getAttribute($key);
        }
    };
    $relation = Relation::noConstraints(
        fn () => $compoundOwner->metafields(),
    );

    expect(fn () => $relation->make())
        ->toThrow(
            InvalidArgumentException::class,
            'Metafield owner identifiers must be integers or strings.',
        );
});
