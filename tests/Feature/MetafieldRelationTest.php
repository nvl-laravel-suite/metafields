<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Metafields\Traits\HasMetafields;

it('compares native UUID owner keys as exact text on the actual source', function (): void {
    $connection = DB::connection();
    $identity = '12345678-1234-5678-90ab-123456789abc';
    $forged = ['12345678-1234-5678-90AB-123456789ABC', '12345678-1234-5678-90ab-123456789abd'];
    $connection->table('test_uuid_metafield_owners')->insert(['id' => $identity]);
    foreach ([$identity, ...$forged] as $key) {
        $connection->table('test_batch_string_metafield_owners')->insert(['id' => $key, 'name' => 'Text key marker']);
    }
    $sources = [$connection];
    if ($connection instanceof MySqlConnection && $connection->isMaria()) {
        config()->set('database.connections.uuid_owner_mysql_alias', [...$connection->getConfig(), 'driver' => 'mysql']);
        $sources[] = DB::connection('uuid_owner_mysql_alias');
        $sources[1]->setPdo($connection->getPdo());
        expect($sources[1]->getDriverName())->toBe('mysql');
    }

    try {
        foreach ($sources as $source) {
            $owner = new class extends Model
            {
                protected $table = 'test_uuid_metafield_owners';

                protected $keyType = 'string';

                public $incrementing = false;
            };
            $owner->setConnection($source->getName());
            foreach ([$identity, ...$forged] as $key) {
                $query = $owner->newQuery();
                MetafieldOwnerPredicate::equal($query, 'id', $key);
                expect($query->count())->toBe($key === $identity ? 1 : 0);
                $query = $owner->newQuery();
                MetafieldOwnerPredicate::identifiers($query, 'id', [$key]);
                expect($query->count())->toBe($key === $identity ? 1 : 0);
            }
            $query = $owner->newQuery()->crossJoin('test_batch_string_metafield_owners as native_key_markers');
            MetafieldOwnerPredicate::columns($query, 'test_uuid_metafield_owners.id', 'native_key_markers.id');
            expect($query->count())->toBe(1);
        }
    } finally {
        DB::purge('uuid_owner_mysql_alias');
    }
});

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
