<?php

declare(strict_types=1);
use Nvl\Metafields\Actions\ImportPlatformMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\ImportPlatformMetafieldDefinitionData;
use Nvl\Metafields\Ownership\MetafieldOwnerCatalog;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\OwnershipCatalogTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipCatalogTestCase::class);
it('copies explicitly granted central definitions and fences stale or revoked imports', function (): void {
    app()->bind(MetafieldCatalogAccess::class, fn () => new class implements MetafieldCatalogAccess
    {
        public function assertCanGrant(string $sourceId, int $revision): void {}

        public function assertCanRevoke(string $grantId): void {}
    });
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::firstOrFail();
    $source = $runner->central('metafields-maintain', $actor, fn () => app(CreateMetafieldDefinitionAction::class)->execute(CreateMetafieldDefinitionPayload::from(['namespace' => 'catalog', 'key' => 'source', 'type' => 'string', 'assignment' => ['ownerType' => 'products', 'section' => 'general'], 'translations' => ['en' => ['title' => 'Source schema']]])));
    $grant = null;
    $copy = null;
    $runner->run(OwnershipCompany::findOrFail(7), $actor, function () use ($source, &$grant, &$copy): void {
        $catalog = app(MetafieldOwnerCatalog::class);
        $grant = $catalog->grant($source->id, $source->revision);
        $data = ImportPlatformMetafieldDefinitionData::from(['grantId' => $grant->id, 'expectedGrantRevision' => $grant->revision, 'expectedSourceRevision' => $source->revision, 'idempotencyKey' => '01900000-0000-7000-8000-000000000002', 'namespace' => 'product', 'key' => 'copy', 'assignment' => ['ownerType' => 'products', 'section' => 'general']]);
        $copy = app(ImportPlatformMetafieldDefinitionAction::class)->execute($data);
        expect($copy->id)->not->toBe($source->id)->and($copy->catalog_source_id)->toBe($source->id)->and($copy->translations->first()->title)->toBe('Source schema');
        $snapshot = $catalog->inspect($grant->id);
        $catalog->revoke($grant->id, $grant->revision);
        expect(fn () => $copy->getConnection()->transaction(fn () => $catalog->persist($snapshot, $data)))->toThrow(OwnershipViolation::class);
        expect($copy->fresh())->not->toBeNull();
    });
    $runner->run(OwnershipCompany::findOrFail(8), $actor, function () use ($grant): void {
        expect(fn () => app(MetafieldOwnerCatalog::class)->inspect($grant->id))->toThrow(OwnershipViolation::class);
    });
});
