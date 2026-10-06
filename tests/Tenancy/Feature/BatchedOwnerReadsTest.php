<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Exceptions\MetafieldBatchReadException;
use Nvl\Metafields\Tests\Fixtures\BatchMetafieldPolicy;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyScenario;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

it('keeps a fixed localized query budget under the real active tenant boundary', function (int $size): void {
    $scenario = MetafieldTenancyScenario::install();
    $definition = $scenario->definition($scenario::A);
    $scenario->run($scenario::A, fn () => $definition->update(['is_translatable' => true]));
    $owners = [];
    for ($index = 0; $index < $size; $index++) {
        $owner = $scenario->owner($scenario::A);
        $owners[] = $owner;
        $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)->execute($owner, $definition->handle, 'local', 'en'));
    }
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);
    $scenario->run($scenario::A, function () use ($owners, $size): void {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(ListAuthorizedOwnersMetafieldsContract::class)->execute($owners, 'bg');
        $budget = count(DB::getQueryLog());
        expect($result->order)->toHaveCount($size);
        expect($budget)->toBe(7);
    });
})->with([1, 25, 100]);

it('rejects a default reference outside the active tenant before returning its identifier', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definition = $scenario->definition($scenario::A);
    $owner = $scenario->owner($scenario::A);
    $foreign = $scenario->owner($scenario::B);
    $scenario->run($scenario::A, fn () => $definition->update(['type' => MetafieldTypeEnum::Reference, 'referenced_model_type' => 'test-owner', 'default_referenced_id' => (string) $foreign->getKey()]));
    app()->instance(MetafieldBatchAuthorization::class, new BatchMetafieldPolicy);

    expect(fn () => $scenario->run($scenario::A, fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$owner])))
        ->toThrow(MetafieldBatchReadException::class, 'unavailable or denied');
});

it('admits only canonical current-tenant owners and keeps values and host filters tenant-local', function (): void {
    $scenario = MetafieldTenancyScenario::install();
    $definitionA = $scenario->definition($scenario::A);
    $definitionB = $scenario->definition($scenario::B);
    $ownerA = $scenario->owner($scenario::A);
    $ownerB = $scenario->owner($scenario::B);
    $scenario->run($scenario::A, fn () => app(SetMetafieldAction::class)->execute($ownerA, $definitionA->handle, 'local'));
    $scenario->run($scenario::B, fn () => app(SetMetafieldAction::class)->execute($ownerB, $definitionB->handle, 'foreign'));
    $scenario->run($scenario::A, fn () => $definitionA->update(['is_filterable' => true]));
    $policy = new BatchMetafieldPolicy;
    app()->instance(MetafieldBatchAuthorization::class, $policy);
    $forged = clone $ownerB;
    $forged->setAttribute('tenant_id', $scenario::A);

    expect(fn () => $scenario->run($scenario::A, fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$ownerA, $forged])))
        ->toThrow(TenantBoundaryViolation::class);
    $result = $scenario->run($scenario::A, fn () => app(ListAuthorizedOwnersMetafieldsContract::class)->execute([$ownerA]));
    expect($result->owners->{TestMetafieldOwner::class}->{(string) $ownerA->getKey()}->fields[0]->value)->toBe('local');
    $matches = $scenario->run($scenario::A, fn () => TestMetafieldOwner::query()->withoutGlobalScopes()->whereNvlMetafield($definitionA->handle, 'local', $policy)->pluck('id')->all());
    expect($matches)->toBe([$ownerA->getKey()]);
});
