<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Exceptions\MetafieldIntegrityException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;

it('rejects duplicate active values and bounds corrupt current rows before translation transfer', function (bool $overflow): void {
    expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
    config(['nvl-metafields.owners.products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products']]);
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $definition = MetafieldDefinition::factory()->create();
    MetafieldDefinitionAssignment::factory()->forDefinition($definition)->forOwnerType('products')->create();
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
