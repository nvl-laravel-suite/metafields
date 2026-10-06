<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Data\OwnersMetafields;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Exceptions\MetafieldIntegrityException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldQueryAdapter;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\BatchSoftDeletingMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\BatchStringMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

beforeEach(function (): void {
    $expectedDriver = getenv('NVL_C1_TEST_DRIVER');
    if (is_string($expectedDriver) && $expectedDriver !== '') {
        expect(DB::connection()->getDriverName())->toBe($expectedDriver);
    }
    config([
        'nvl-translatable.locales' => ['en', 'bg'],
        'nvl-translatable.fallback_locales' => ['en'],
        'nvl-metafields.owners' => [
            'products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products'],
            'strings' => ['model' => BatchStringMetafieldOwner::class, 'label' => 'Strings'],
            'live-products' => ['model' => BatchSoftDeletingMetafieldOwner::class, 'label' => 'Live products'],
        ],
        'nvl-metafields.reference_models' => ['products' => TestMetafieldOwner::class],
    ]);
    $driver = Schema::getConnection()->getDriverName();
    Schema::create('test_batch_string_metafield_owners', static fn (Blueprint $table) => batchStringMetafieldOwnerSchema($table, $driver));
});

function batchStringMetafieldOwnerSchema(Blueprint $table, string $driver): void
{
    $id = $table->string('id')->primary();
    if (in_array($driver, ['mysql', 'mariadb'], true)) {
        $id->collation('utf8mb4_bin');
    }
    $table->string('name');
    $table->timestamps();
    $table->softDeletes();
}

/** @param list<array{query: string}> $queries */
function batchMetafieldStorageQueries(array $queries): Collection
{
    $tables = [
        (new Metafield)->getTable(),
        (new MetafieldDefinition)->getTable(),
        (new MetafieldDefinitionAssignment)->getTable(),
        (new MetafieldDefinitionTranslation)->getTable(),
        (new MetafieldTranslation)->getTable(),
    ];

    return collect($queries)->filter(static fn (array $query): bool => Str::contains($query['query'], $tables));
}

function batchMetafieldDefinition(MetafieldTypeEnum $type = MetafieldTypeEnum::String, string $namespace = 'details', string $alias = 'products'): MetafieldDefinition
{
    $definition = MetafieldDefinition::factory()->ofType($type)->state(['namespace' => $namespace])->create();
    MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType($alias)->create();

    return $definition;
}

function batchMetafieldPolicy(): BatchMetafieldPolicy
{
    $policy = new BatchMetafieldPolicy;
    app()->instance(MetafieldBatchAuthorization::class, $policy);

    return $policy;
}

it('returns an empty object map without resolving an unsupported policy or querying storage', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([]);

    expect($result->owners)->toBeInstanceOf(stdClass::class)
        ->and($result->order)->toBe([])
        ->and(json_encode($result->owners))->toBe('{}')
        ->and(DB::getQueryLog())->toBe([]);
});

it('preserves explicit consumer bindings when the provider registers again', function (): void {
    $reader = new class implements ListAuthorizedOwnersMetafieldsContract
    {
        public function execute(array $owners, ?string $locale = null): OwnersMetafields
        {
            return new OwnersMetafields(new stdClass, []);
        }
    };
    $policy = batchMetafieldPolicy();
    app()->instance(ListAuthorizedOwnersMetafieldsContract::class, $reader);
    (new MetafieldsServiceProvider(app()))->register();

    expect(app(ListAuthorizedOwnersMetafieldsContract::class))->toBe($reader)
        ->and(app(MetafieldBatchAuthorization::class))->toBe($policy);
});

it('has a fixed localized SQL budget at one, twenty-five and one hundred owners', function (int $size): void {
    batchMetafieldPolicy();
    $owners = [];
    for ($index = 0; $index < 100; $index++) {
        $owners[] = TestMetafieldOwner::query()->create(['name' => 'Owner '.$index]);
    }
    $default = batchMetafieldDefinition();
    $default->setDefaultValue('fallback');
    $default->save();
    $localized = batchMetafieldDefinition();
    $localized->update(['is_translatable' => true]);
    MetafieldDefinitionTranslation::query()->create(['metafield_definition_id' => $localized->id, 'locale' => 'en', 'title' => 'English title']);
    foreach ($owners as $owner) {
        $value = Metafield::factory()->forDefinition($localized)->forOwner($owner)->withValue('base')->create();
        MetafieldTranslation::query()->create(['metafield_id' => $value->id, 'locale' => 'en', 'value' => 'English value']);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute(array_slice($owners, 0, $size), 'bg');
    $budget = count(DB::getQueryLog());
    expect($result->order)->toHaveCount($size);
    foreach ($result->owners->{TestMetafieldOwner::class} as $fields) {
        expect($fields->fields)->toHaveCount(2);
        $byId = collect($fields->fields)->keyBy('definitionId');
        expect($byId[$default->id]->value)->toBe('fallback')
            ->and($byId[$default->id]->usesDefaultValue)->toBeTrue()
            ->and($byId[$localized->id]->title)->toBe('English title')
            ->and($byId[$localized->id]->value)->toBe('English value');
    }

    expect($budget)->toBe(8);
})->with([1, 25, 100]);

it('uses canonical owners and exact type-key pairs for equal and crossed integer/string keys', function (bool $equal): void {
    $policy = batchMetafieldPolicy();
    $integer = TestMetafieldOwner::query()->create(['name' => 'Canonical']);
    $otherInteger = TestMetafieldOwner::query()->create(['name' => 'Other']);
    $string = BatchStringMetafieldOwner::query()->create(['id' => (string) ($equal ? $integer->getKey() : $otherInteger->getKey()), 'name' => 'String']);
    $first = batchMetafieldDefinition();
    $second = batchMetafieldDefinition(alias: 'strings');
    Metafield::factory()->forDefinition($first)->forOwner($integer)->withValue('integer')->create();
    Metafield::factory()->forDefinition($first)->forOwner($otherInteger)->withValue('wrong integer')->create();
    Metafield::factory()->forDefinition($second)->forOwner($string)->withValue('string')->create();
    $integer->name = 'Forged';
    $integer->setRawAttributes([...$integer->getAttributes(), 'id' => '0'.$integer->getKey()]);

    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$string, $integer, $string]);

    expect($policy->canonicalOwnerNames)->toBe(['String', 'Canonical'])
        ->and($result->order)->toHaveCount(2)
        ->and($result->owners->{TestMetafieldOwner::class}->{(string) $integer->getKey()}->fields[0]->value)->toBe('integer')
        ->and($result->owners->{BatchStringMetafieldOwner::class}->{(string) $string->getKey()}->fields[0]->value)->toBe('string');
})->with([true, false]);

it('applies SQL visibility before loading defaults, values and references and contains policy OR clauses', function (): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $visible = batchMetafieldDefinition();
    $hidden = batchMetafieldDefinition(namespace: 'hidden');
    $private = batchMetafieldDefinition(MetafieldTypeEnum::Reference, 'private');
    $private->update(['referenced_model_type' => 'products', 'default_referenced_id' => '9999']);
    Metafield::factory()->forDefinition($visible)->forOwner($owner)->withValue('hidden')->create();
    Metafield::factory()->forDefinition($hidden)->forOwner($owner)->withValue('secret')->create();
    Metafield::factory()->forDefinition($visible)->state(['metafieldable_type' => 'unrequested', 'metafieldable_id' => '9999', 'value' => 'foreign'])->create();

    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]);

    $fields = $result->owners->{TestMetafieldOwner::class}->{(string) $owner->getKey()}->fields;
    expect($fields)->toHaveCount(1)->and($fields[0]->definitionId)->toBe($visible->id)
        ->and($fields[0]->hasStoredValue)->toBeFalse()->and($fields[0]->value)->toBeNull();
});

it('rejects scoped-out and deleted owners before package queries', function (string $state): void {
    batchMetafieldPolicy();
    $owner = BatchStringMetafieldOwner::query()->create(['id' => 'ABC', 'name' => $state === 'hidden' ? 'hidden' : 'Owner']);
    if ($state === 'deleted') {
        $owner->delete();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))->toThrow(TenantBoundaryViolation::class);
    expect(batchMetafieldStorageQueries(DB::getQueryLog()))->toHaveCount(0);
})->with(['hidden', 'deleted']);

it('detects forbidden package reads regardless of SQL identifier quoting', function (string $quote): void {
    $packageSql = 'select * from '.$quote.(new Metafield)->getTable().$quote;
    $queries = [['query' => $packageSql], ['query' => 'select * from '.$quote.'test_metafield_owners'.$quote]];

    expect(batchMetafieldStorageQueries($queries))->toHaveCount(1)
        ->and(batchMetafieldStorageQueries($queries)->first()['query'])->toBe($packageSql);
})->with(['"', '`', '']);

it('generates an exact string owner key while preserving case-insensitive table defaults', function (string $driver): void {
    config(['database.connections.fixture_ddl' => [
        'driver' => $driver,
        'host' => 'localhost',
        'database' => 'unused',
        'username' => 'unused',
        'password' => 'unused',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]]);
    $connection = DB::connection('fixture_ddl');
    $connection->useDefaultSchemaGrammar();
    $blueprint = new Blueprint($connection, 'test_batch_string_metafield_owners');
    $blueprint->create();
    batchStringMetafieldOwnerSchema($blueprint, $driver);
    $sql = implode("\n", $blueprint->toSql());

    expect($sql)->toContain('`id` varchar(255) collate \'utf8mb4_bin\'')
        ->and($sql)->toContain('default character set utf8mb4 collate \'utf8mb4_unicode_ci\'');
})->with(['mysql', 'mariadb']);

it('fails unsupported imperative policies with an actionable package exception', function (): void {
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))
        ->toThrow(MetafieldBatchReadException::class, 'Bind MetafieldBatchAuthorization');
});

it('compares stored scalar values through the prefixed host scope while preserving selections and scopes', function (): void {
    $policy = batchMetafieldPolicy();
    $upper = BatchStringMetafieldOwner::query()->create(['id' => 'ABC', 'name' => 'Upper']);
    $lower = BatchStringMetafieldOwner::query()->create(['id' => 'abc', 'name' => 'Lower']);
    $hidden = BatchStringMetafieldOwner::query()->create(['id' => 'hidden', 'name' => 'hidden']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Boolean, alias: 'strings');
    $definition->update(['is_filterable' => true]);
    foreach ([$upper, $hidden] as $owner) {
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('1')->create();
    }
    Metafield::factory()->forDefinition($definition)->forOwner($lower)->withValue('0')->create();

    $query = BatchStringMetafieldOwner::query()->select(['id', 'name'])->whereNvlMetafield($definition->handle, true, $policy);
    expect($query->getQuery()->columns)->toBe(['id', 'name'])
        ->and($query->get()->pluck('id')->all())->toBe(['ABC']);
});

it('keeps deleted host owners outside stored value filters after global scopes are removed', function (): void {
    $policy = batchMetafieldPolicy();
    $owner = BatchStringMetafieldOwner::query()->create(['id' => 'deleted-owner', 'name' => 'Visible']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Boolean, alias: 'strings');
    $definition->update(['is_filterable' => true]);
    Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('1')->create();
    $owner->delete();

    expect(BatchStringMetafieldOwner::query()->withoutGlobalScopes()->whereNvlMetafield($definition->handle, true, $policy)->pluck('id')->all())->toBe([]);
});

it('rejects host unions before definition storage access', function (): void {
    $policy = batchMetafieldPolicy();
    $query = BatchStringMetafieldOwner::query()->union(BatchStringMetafieldOwner::query());
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(fn () => $query->whereNvlMetafield('details.enabled', true, $policy))->toThrow(TenantBoundaryViolation::class);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

it('keeps caller OR predicates inside mandatory live owner and stored value guards', function (bool $direct): void {
    $policy = batchMetafieldPolicy();
    $unmatched = BatchStringMetafieldOwner::query()->create(['id' => 'unmatched', 'name' => 'Unmatched']);
    $matched = BatchStringMetafieldOwner::query()->create(['id' => 'matched', 'name' => 'Matched']);
    $deleted = BatchStringMetafieldOwner::query()->create(['id' => 'deleted', 'name' => 'Deleted']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Boolean, alias: 'strings');
    $definition->update(['is_filterable' => true]);
    foreach ([$matched, $deleted] as $owner) {
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('1')->create();
    }
    $deleted->delete();
    $query = BatchStringMetafieldOwner::query()->withoutGlobalScopes()
        ->whereKey($unmatched->getKey())->orWhere('id', $matched->getKey())->orWhere('id', $deleted->getKey());
    $filtered = $direct
        ? app(OwnerMetafieldQueryAdapter::class)->apply($query, $definition->handle, true, $policy)
        : $query->whereNvlMetafield($definition->handle, true, $policy);

    expect($filtered->pluck('id')->all())->toBe([$matched->getKey()]);
})->with([false, true]);

it('retains a canonical custom soft delete column in host stored value filters', function (): void {
    Schema::create((new BatchSoftDeletingMetafieldOwner)->getTable(), static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
        $table->softDeletes(BatchSoftDeletingMetafieldOwner::DELETED_AT);
    });
    $policy = batchMetafieldPolicy();
    $live = BatchSoftDeletingMetafieldOwner::query()->create(['name' => 'Live']);
    $deleted = BatchSoftDeletingMetafieldOwner::query()->create(['name' => 'Deleted']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Boolean, alias: 'live-products');
    $definition->update(['is_filterable' => true]);
    foreach ([$live, $deleted] as $owner) {
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('1')->create();
    }
    $deleted->delete();

    expect(BatchSoftDeletingMetafieldOwner::query()->withoutGlobalScopes()
        ->whereNvlMetafield($definition->handle, true, $policy)->pluck('id')->all())->toBe([$live->getKey()]);
});

it('loads stored and default reference targets once for a complete batch', function (int $size): void {
    batchMetafieldPolicy();
    $target = TestMetafieldOwner::query()->create(['name' => 'allowed-reference']);
    $stored = batchMetafieldDefinition(MetafieldTypeEnum::Reference);
    $stored->update(['referenced_model_type' => 'products']);
    $default = batchMetafieldDefinition(MetafieldTypeEnum::ReferenceList);
    $default->update(['referenced_model_type' => 'products', 'default_value' => json_encode([(string) $target->getKey()])]);
    $owners = [];
    for ($index = 0; $index < $size; $index++) {
        $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
        $owners[] = $owner;
        Metafield::factory()->forDefinition($stored)->forOwner($owner)->state(['value' => null, 'referenced_id' => (string) $target->getKey()])->create();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute($owners);
    $budget = count(DB::getQueryLog());
    expect($budget)->toBe(9);
    foreach ($result->owners->{TestMetafieldOwner::class} as $fields) {
        $byId = collect($fields->fields)->keyBy('definitionId');
        expect($byId[$stored->id]->value)->toBe((string) $target->getKey())
            ->and($byId[$default->id]->value)->toBe([(string) $target->getKey()])
            ->and($byId[$default->id]->defaultValue)->toBe([(string) $target->getKey()]);
    }
})->with([1, 25, 100]);

it('fails closed for denied stored or default references without returning private identifiers', function (bool $default): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $target = TestMetafieldOwner::query()->create(['name' => 'private']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Reference);
    $definition->update(['referenced_model_type' => 'products', 'default_referenced_id' => $default ? (string) $target->getKey() : null]);
    if (! $default) {
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->state(['value' => null, 'referenced_id' => (string) $target->getKey()])->create();
    }
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))
        ->toThrow(MetafieldBatchReadException::class, 'unavailable or denied');
})->with([true, false]);

it('matches the existing admitted single-owner scalar projection', function (): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Integer);
    Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('12')->create();
    $single = app(ListOwnerMetafieldsAction::class)->execute($owner)->first();
    $batch = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]);

    expect($batch->owners->{TestMetafieldOwner::class}->{(string) $owner->getKey()}->fields[0]->toArray())->toBe($single->toArray());
});

it('rejects definition overflow before loading related definition or translation rows', function (): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    for ($index = 0; $index < 101; $index++) {
        batchMetafieldDefinition();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))
        ->toThrow(MetafieldBatchReadException::class, '100 active definitions');
    $translationTables = [(new MetafieldDefinitionTranslation)->getTable(), (new MetafieldTranslation)->getTable()];
    expect(collect(DB::getQueryLog())->filter(static fn (array $query): bool => Str::contains($query['query'], $translationTables)))->toHaveCount(0);
});

it('validates complete input and declared connections before any storage SQL', function (string $case): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $owners = [$owner];
    if ($case === 'unpersisted') {
        $owners[] = new TestMetafieldOwner(['id' => 2]);
    }
    if ($case === 'maximum') {
        $owners = array_fill(0, 101, $owner);
    }
    if ($case === 'connection') {
        config(['database.connections.foreign' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $owner->setConnection('foreign');
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute($owners))->toThrow($case === 'connection' ? TenantBoundaryViolation::class : InvalidArgumentException::class);
    expect(DB::getQueryLog())->toBe([]);
})->with(['unpersisted', 'maximum', 'connection']);

it('preserves native custom morph map keys and exact object JSON shape', function (): void {
    config(['nvl-metafields.reference_models' => []]);
    Relation::morphMap(['products' => TestMetafieldOwner::class, 'strings' => BatchStringMetafieldOwner::class]);
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $definition = batchMetafieldDefinition();
    Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('value')->create();

    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]);
    $json = json_decode(json_encode($result->owners), false);
    expect($result->order)->toBe([['type' => 'products', 'id' => (string) $owner->getKey()]])
        ->and($json->products)->toBeInstanceOf(stdClass::class)
        ->and($json->products->{(string) $owner->getKey()}->fields)->toHaveCount(1);
});

it('compares numeric stored values numerically and excludes missing and default-only rows', function (): void {
    $policy = batchMetafieldPolicy();
    $match = TestMetafieldOwner::query()->create(['name' => 'Match']);
    $missing = TestMetafieldOwner::query()->create(['name' => 'Missing']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::Integer);
    $definition->update(['is_filterable' => true, 'default_value' => '10']);
    Metafield::factory()->forDefinition($definition)->forOwner($match)->withValue('10')->create();
    $withoutDefault = batchMetafieldDefinition(MetafieldTypeEnum::Integer);
    $withoutDefault->update(['is_filterable' => true]);

    expect(TestMetafieldOwner::query()->whereNvlMetafield($definition->handle, 2, $policy, '>')->pluck('id')->all())->toBe([$match->getKey()])
        ->and(TestMetafieldOwner::query()->whereNvlMetafield($definition->handle, 10, $policy)->whereKey($missing->getKey())->exists())->toBeFalse()
        ->and(TestMetafieldOwner::query()->whereNvlMetafield($withoutDefault->handle, 10, $policy)->exists())->toBeFalse();
});

it('returns UUID owner keys and rejects an absent owner rather than defaulting its fields', function (): void {
    batchMetafieldPolicy();
    $id = (string) Str::uuid();
    $owner = BatchStringMetafieldOwner::query()->create(['id' => $id, 'name' => 'Owner']);
    $definition = batchMetafieldDefinition(alias: 'strings');
    $definition->update(['default_value' => 'default']);
    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]);
    expect($result->owners->{BatchStringMetafieldOwner::class}->{$id}->fields[0]->value)->toBe('default');
    BatchStringMetafieldOwner::query()->whereKey($id)->forceDelete();
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))->toThrow(TenantBoundaryViolation::class);
});

it('rejects duplicate active values and bounds corrupt current rows before translation transfer', function (bool $overflow): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $definition = batchMetafieldDefinition();
    Schema::table((new Metafield)->getTable(), static fn (Blueprint $table) => $table->dropUnique('metafields_owner_definition_unique'));
    $prototype = Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('value')->make()->getAttributes();
    $rows = [];
    for ($index = 0; $index < ($overflow ? 10001 : 2); $index++) {
        $rows[] = ['id' => (string) Str::uuid(), ...$prototype];
        if (count($rows) === 1000) {
            DB::table((new Metafield)->getTable())->insert($rows);
            $rows = [];
        }
    }
    if ($rows !== []) {
        DB::table((new Metafield)->getTable())->insert($rows);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))
        ->toThrow($overflow ? MetafieldBatchReadException::class : MetafieldIntegrityException::class);
    if ($overflow) {
        expect(collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], (new MetafieldTranslation)->getTable())))->toHaveCount(0);
    }
})->with([false, true]);

it('bounds stored and default reference identifiers before target SQL', function (): void {
    batchMetafieldPolicy();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $definition = batchMetafieldDefinition(MetafieldTypeEnum::ReferenceList);
    $definition->update(['referenced_model_type' => 'products', 'default_value' => json_encode(range(1, 1001))]);
    expect(fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]))
        ->toThrow(MetafieldBatchReadException::class, '1,000 distinct');
});

it('admits the maximum one thousand references through one bounded target query', function (): void {
    batchMetafieldPolicy();
    DB::table('test_metafield_owners')->insert(array_fill(0, 1000, ['name' => 'allowed-reference']));
    $targetIds = TestMetafieldOwner::query()->orderBy('id')->pluck('id')->all();
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    foreach (array_chunk($targetIds, 100) as $ids) {
        $definition = batchMetafieldDefinition(MetafieldTypeEnum::ReferenceList);
        $definition->update(['referenced_model_type' => 'products', 'default_value' => json_encode($ids)]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner]);
    $targetQueries = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], 'test_metafield_owners'));
    expect($targetQueries)->toHaveCount(2);
    foreach ($result->owners->{TestMetafieldOwner::class}->{(string) $owner->getKey()}->fields as $field) {
        expect($field->value)->toHaveCount(100);
    }
});

it('rejects malformed or unsupported host comparisons explicitly', function (string $case): void {
    $policy = batchMetafieldPolicy();
    $definition = batchMetafieldDefinition($case === 'reference' ? MetafieldTypeEnum::Reference : MetafieldTypeEnum::String);
    $definition->update(['is_filterable' => true, 'is_translatable' => $case === 'localized']);
    expect(fn () => TestMetafieldOwner::query()->whereNvlMetafield($definition->handle, 'value', $policy, $case === 'operator' ? 'like' : '='))
        ->toThrow(MetafieldBatchReadException::class);
})->with(['reference', 'localized', 'operator']);

it('generates exact portable owner text correlations for each supported SQL driver', function (string $driver, string $expected): void {
    config(['database.connections.comparison' => ['driver' => $driver, 'host' => 'localhost', 'database' => 'unused', 'username' => 'unused', 'password' => 'unused']]);
    $query = (new TestMetafieldOwner)->setConnection('comparison')->newQuery()->withoutGlobalScopes();
    MetafieldOwnerPredicate::columns($query, 'package.owner_id', 'host.id');
    MetafieldOwnerPredicate::equal($query, 'package.owner_type', 'CaseSensitive');

    expect($query->toSql())->toContain($expected)->and($query->getBindings())->toBe(['CaseSensitive']);
})->with([
    ['pgsql', 'CAST("package"."owner_id" AS TEXT) COLLATE "C" = CAST("host"."id" AS TEXT) COLLATE "C"'],
    ['mysql', 'CAST(`package`.`owner_id` AS BINARY) = CAST(`host`.`id` AS BINARY)'],
    ['mariadb', 'CAST(`package`.`owner_id` AS BINARY) = CAST(`host`.`id` AS BINARY)'],
]);
