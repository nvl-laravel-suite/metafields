<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\HistoricalOwnershipTestCase;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(HistoricalOwnershipTestCase::class);
it('adopts the released legacy constraints in bounded pages and admits new native children', function (): void {
    config(['nvl-tenancy.sharing.metafields' => 'copy']);
    foreach (glob(__DIR__.'/../../database/tenancy/*.php') ?: [] as $path) {
        if (str_contains($path, '110003')) {
            continue;
        }(require $path)->up();
    }
    $legacy = '01900000-0000-7000-8000-000000000003';
    $id = (string) Str::uuid();
    $translation = (string) Str::uuid();
    $assignment = (string) Str::uuid();
    $root = MetafieldModelRegistry::newInstance();
    $connection = $root->getConnection();
    $connection->table($root->getTable())->insert(['id' => $id, 'namespace' => 'legacy', 'key' => 'field', 'handle' => 'legacy.field', 'active_handle' => 'legacy.field', 'type' => 'string', 'tenant_id' => $legacy, 'ownership_key' => 'tenant:'.$legacy]);
    $connection->table(MetafieldsTables::get(MetafieldsTables::DefinitionsI18n))->insert(['id' => $translation, 'metafield_definition_id' => $id, 'locale' => 'en', 'title' => 'Legacy label', 'tenant_id' => $legacy, 'ownership_key' => 'tenant:'.$legacy]);
    $connection->table(MetafieldsTables::get(MetafieldsTables::DefinitionAssignments))->insert(['id' => $assignment, 'definition_id' => $id, 'owner_type' => 'products', 'section' => 'general', 'is_active' => true, 'tenant_id' => $legacy, 'ownership_key' => 'tenant:'.$legacy]);
    (require __DIR__.'/../../database/tenancy/current/2026_09_16_110003_nvl_metafields_constrain_metafield_tenant_ownership.php')->up();
    $reference = app(OwnerIdentityResolver::class)->reference(OwnershipCompany::findOrFail(7));
    $mappings = [];
    foreach (['metafields.definitions' => $id, 'metafields.definition-translations' => $translation, 'metafields.definition-assignments' => $assignment] as $resource => $record) {
        $mappings[] = new OwnershipMapping($resource, $record, $reference, legacyTenantId: $legacy);
    }
    $operation = new OwnershipOperation('convert-metafields', 'operator', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['metafields', 'fixture'], $mappings, $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
        $plan = $coordinator->resume($plan->id);
    }
    $coordinator->activate($plan, $operation);
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function () use ($id, $legacy): void {
        $definition = MetafieldModelRegistry::query()->findOrFail($id);
        expect($definition->tenant_id)->toBe($legacy)->and($definition->displayTitle('en'))->toBe('Legacy label');
        $owner = OwnershipMetafieldScenario::owner();
        $value = app(SetMetafieldAction::class)->execute($owner, 'legacy.field', 'New value');
        expect($value->getValue())->toBe('New value');
    });
});

it('refuses activation when a reviewed definition default refers to a different native owner', function (): void {
    $id = (string) Str::uuid();
    $target = (string) Str::uuid();
    DB::table(MetafieldModelRegistry::newInstance()->getTable())->insert(['id' => $id, 'namespace' => 'legacy', 'key' => 'ref', 'handle' => 'legacy.ref', 'active_handle' => 'legacy.ref', 'type' => 'reference', 'referenced_model_type' => 'products', 'default_referenced_id' => $target]);
    DB::table((new OwnershipMetafieldOwner)->getTable())->insert(['id' => $target, 'company_id' => 8, 'name' => 'Foreign target']);
    $identity = app(OwnerIdentityResolver::class);
    $a = $identity->reference(OwnershipCompany::findOrFail(7));
    $b = $identity->reference(OwnershipCompany::findOrFail(8));
    $operation = new OwnershipOperation('verify-metafield-defaults', 'operator', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['metafields', 'fixture'], [new OwnershipMapping('metafields.definitions', $id, $a), new OwnershipMapping('fixture.metafield-owners', $target, $b)], $operation);
    while (! $coordinator->backfill($plan, 1, $operation)) {
        $plan = $coordinator->resume($plan->id);
    }
    expect(fn () => $coordinator->activate($plan, $operation))->toThrow(OwnershipViolation::class);
});
