<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Services\MetafieldReader;
use Nvl\Metafields\Tests\Fixtures\ActorAwareBatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\BatchStringMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldActor;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Gate::define('metafields.read', static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    config(['nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products']]]);
});

it('returns an explicit empty map without resolving optional batch policy', function (): void {
    $result = app(MetafieldReader::class)->forOwners([], SystemActor::Background);
    expect($result['order'])->toBe([])
        ->and(json_encode($result['owners']))->toBe('{}');
});

it('keeps native fields and bounded package SQL across one, twenty-five and one hundred owners', function (): void {
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $definition = MetafieldDefinition::factory()->create();
    MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
    $owners = [];
    foreach (range(1, 100) as $index) {
        $owner = TestMetafieldOwner::query()->create(['name' => 'Owner '.$index]);
        $owners[] = $owner;
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('value-'.$index)->create();
    }
    $sql = [];
    DB::listen(static function (QueryExecuted $query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $budgets = [];
    foreach ([1, 25, 100] as $size) {
        $sql = [];
        $result = app(MetafieldReader::class)->forOwners(array_slice($owners, 0, $size), SystemActor::Background, 'en');
        $domainQueries = array_values(array_filter($sql, static function (string $query): bool {
            $normalized = str_replace(['"', '`'], '', strtolower($query));

            return str_contains($normalized, 'nvl_metafields_');
        }));
        $budgets[$size] = count($domainQueries);
        expect($budgets[$size])->toBeGreaterThan(0)
            ->and($result['order'])->toHaveCount($size);
        foreach ($result['owners']->{TestMetafieldOwner::class} as $entry) {
            expect($entry->fields)->toHaveCount(1)
                ->and($entry->fields[0]['definition'])->toBeInstanceOf(MetafieldDefinition::class)
                ->and($entry->fields[0]['value'])->toBeInstanceOf(Metafield::class);
        }
    }
    expect($budgets[1])->toBeLessThanOrEqual(8)
        ->and($budgets[25])->toBeLessThanOrEqual($budgets[1] + 2)
        ->and($budgets[100])->toBeLessThanOrEqual($budgets[1] + 2);
});

it('preserves mixed owner types and explicit empty field lists', function (): void {
    config(['nvl-metafields.owners.strings' => ['model' => BatchStringMetafieldOwner::class, 'label' => 'Strings']]);
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $integer = TestMetafieldOwner::query()->create(['name' => 'Integer']);
    $string = BatchStringMetafieldOwner::query()->create(['id' => (string) $integer->id, 'name' => 'String']);
    $definition = MetafieldDefinition::factory()->create();
    MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
    Metafield::factory()->forDefinition($definition)->forOwner($integer)->withValue('integer only')->create();

    $result = app(MetafieldReader::class)->forOwners([$string, $integer, $string], SystemActor::Background);
    expect($result['order'])->toHaveCount(2)
        ->and($result['owners']->{TestMetafieldOwner::class}->{(string) $integer->id}->fields[0]['value']->getValue())->toBe('integer only')
        ->and($result['owners']->{BatchStringMetafieldOwner::class}->{(string) $string->id}->fields)->toBe([]);
});

it('keeps policy OR clauses inside requested owner and assignment predicates', function (): void {
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Visible']);
    foreach (['visible', 'hidden', 'private'] as $namespace) {
        $definition = MetafieldDefinition::factory()->create(['namespace' => $namespace, 'key' => 'label']);
        MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue($namespace)->create();
    }
    $result = app(MetafieldReader::class)->forOwners([$owner], SystemActor::Background);
    $fields = $result['owners']->{TestMetafieldOwner::class}->{(string) $owner->id}->fields;
    expect(array_map(static fn (array $field): string => $field['definition']->namespace, $fields))->toBe(['visible']);
});

it('rejects absent owners before returning stored or default field data', function (): void {
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Deleted']);
    $owner->delete();
    expect(fn () => app(MetafieldReader::class)->forOwners([$owner], SystemActor::Background))
        ->toThrow(InvalidArgumentException::class);
});

it('uses the passed actor for each batch on the same policy instance despite a different guard user', function (): void {
    Gate::define('metafields.read', static fn (TestMetafieldActor $actor): bool => true);
    $first = TestMetafieldActor::query()->create(['name' => 'First']);
    $second = TestMetafieldActor::query()->create(['name' => 'Second']);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Shared']);
    foreach ([$first, $second] as $actor) {
        $definition = MetafieldDefinition::factory()->create(['namespace' => 'actor_'.$actor->id, 'key' => 'label']);
        MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
        Metafield::factory()->forDefinition($definition)->forOwner($owner)->withValue('value-'.$actor->id)->create();
    }
    $policy = new ActorAwareBatchMetafieldPolicy;
    app()->instance(MetafieldBatchAuthorization::class, $policy);
    $reader = app(MetafieldReader::class);
    Auth::guard()->setUser($second);
    $forFirst = $reader->forOwners([$owner], $first);
    Auth::guard()->setUser($first);
    $forSecond = $reader->forOwners([$owner], $second);
    $againForFirst = $reader->forOwners([$owner], $first);
    $fieldsFor = static fn (array $batch): array => $batch['owners']->{TestMetafieldOwner::class}->{(string) $owner->id}->fields;
    expect($fieldsFor($forFirst))->toHaveCount(1)
        ->and($fieldsFor($forFirst)[0]['definition']->namespace)->toBe('actor_'.$first->id)
        ->and($fieldsFor($forFirst)[0]['value']->getValue())->toBe('value-'.$first->id)
        ->and($fieldsFor($forSecond))->toHaveCount(1)
        ->and($fieldsFor($forSecond)[0]['definition']->namespace)->toBe('actor_'.$second->id)
        ->and($fieldsFor($forSecond)[0]['value']->getValue())->toBe('value-'.$second->id)
        ->and($fieldsFor($againForFirst)[0]['value']->getValue())->toBe('value-'.$first->id)
        ->and($policy->seenActors)->toBe([$first->id, $second->id, $first->id]);
});

it('passes the explicit actor through the stored scalar host filter scope', function (): void {
    Gate::define('metafields.read', static fn (TestMetafieldActor $actor): bool => true);
    $first = TestMetafieldActor::query()->create(['name' => 'First']);
    $second = TestMetafieldActor::query()->create(['name' => 'Second']);
    $definition = MetafieldDefinition::factory()->create(['namespace' => 'shared', 'key' => 'label', 'is_filterable' => true]);
    MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
    $firstOwner = TestMetafieldOwner::query()->create(['name' => 'First owner']);
    $secondOwner = TestMetafieldOwner::query()->create(['name' => 'Second owner']);
    Metafield::factory()->forDefinition($definition)->forOwner($firstOwner)->withValue('value-'.$first->id)->create();
    Metafield::factory()->forDefinition($definition)->forOwner($secondOwner)->withValue('value-'.$second->id)->create();
    $policy = new ActorAwareBatchMetafieldPolicy;
    Auth::guard()->setUser($second);
    expect(TestMetafieldOwner::query()->whereNvlMetafield('shared.label', 'value-'.$first->id, $first, $policy)->pluck('id')->all())
        ->toBe([$firstOwner->id]);
    Auth::guard()->setUser($first);
    expect(TestMetafieldOwner::query()->whereNvlMetafield('shared.label', 'value-'.$second->id, $second, $policy)->pluck('id')->all())
        ->toBe([$secondOwner->id]);
});
