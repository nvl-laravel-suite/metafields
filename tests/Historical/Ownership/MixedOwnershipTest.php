<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Data\SyncOwnerMetafieldValuePayload;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Ownership\MetafieldResourceRegistrar;
use Nvl\Metafields\Tests\Fixtures\MixedMetafieldOwnershipTestCase;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Spatie\LaravelData\DataCollection;

uses(MixedMetafieldOwnershipTestCase::class);
it('rejects public sync of a retained foreign host when definitions are unscoped', function (): void {
    config(['nvl-metafields.owners' => ['products' => ['model' => OwnershipMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general'], 'runtime_status' => 'live']]]);
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::firstOrFail();
    $foreign = $runner->run(OwnershipCompany::findOrFail(8), $actor, fn () => OwnershipMetafieldScenario::owner());
    $definition = new MetafieldDefinition;
    $definitionId = (string) Str::uuid();
    $definition->getConnection()->table($definition->getTable())->insert(['id' => $definitionId, 'namespace' => 'product', 'key' => 'text', 'handle' => 'product.text', 'active_handle' => 'product.text', 'type' => 'string']);
    $assignment = new MetafieldDefinitionAssignment;
    $assignment->getConnection()->table($assignment->getTable())->insert(['id' => (string) Str::uuid(), 'definition_id' => $definitionId, 'owner_type' => 'products', 'section' => 'general']);
    $runner->run(OwnershipCompany::findOrFail(7), $actor, function () use ($foreign, $definitionId): void {
        expect(fn () => app(SyncOwnerMetafieldsAction::class)->execute($foreign, new SyncOwnerMetafieldsPayload(new DataCollection(SyncOwnerMetafieldValuePayload::class, [new SyncOwnerMetafieldValuePayload($definitionId, value: 'Foreign mutation')]))))->toThrow(OwnershipConfigurationInvalid::class);
        expect(fn () => Metafield::query()->get())->toThrow(OwnershipConfigurationInvalid::class);
        expect(fn () => (new MetafieldTranslation)->saveQuietly())->toThrow(OwnershipConfigurationInvalid::class);
        expect(fn () => app(MetafieldResourceRegistrar::class)->register(new OwnershipResourceRegistry))->toThrow(OwnershipConfigurationInvalid::class);
        expect($foreign->getConnection()->table((new Metafield)->getTable())->count())->toBe(0);
    });
});
