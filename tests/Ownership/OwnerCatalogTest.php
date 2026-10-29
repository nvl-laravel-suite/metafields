<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Event;
use Nvl\Metafields\Contracts\MetafieldCatalogAccess;
use Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited;
use Nvl\Metafields\Ownership\MetafieldOwnerCatalog;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\OwnershipCatalogTestCase;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OwnershipCatalogTestCase::class);
it('copies explicitly granted central definitions and fences stale or revoked imports', function (bool $stringRevisions): void {
    Event::fake([MetafieldDefinitionCatalogGrantAudited::class]);
    app()->bind(MetafieldCatalogAccess::class, fn () => new class implements MetafieldCatalogAccess
    {
        public function assertCanGrant(string $sourceId, int $revision): void {}

        public function assertCanRevoke(string $grantId): void {}
    });
    $runner = app(OwnershipRunner::class);
    $actor = OwnershipActor::firstOrFail();
    $source = $runner->central('metafields-maintain', $actor, fn () => app(MetafieldDefinitions::class)->create(['namespace' => 'catalog', 'key' => 'source', 'type' => 'string', 'assignment' => ['ownerType' => 'products', 'section' => 'general'], 'translations' => ['en' => ['title' => 'Source schema']]], $actor));
    $grant = null;
    $copy = null;
    $runner->run(OwnershipCompany::findOrFail(7), $actor, function () use ($source, $stringRevisions, &$grant, &$copy): void {
        $catalog = app(MetafieldOwnerCatalog::class);
        $grant = app(MetafieldDefinitions::class)->grant($source->id, $source->revision, OwnershipActor::firstOrFail());
        $data = ['grantId' => $grant->id, 'expectedGrantRevision' => $grant->revision, 'expectedSourceRevision' => $source->revision, 'idempotency_key' => '01900000-0000-7000-8000-000000000002', 'namespace' => 'product', 'key' => 'copy', 'assignment' => ['ownerType' => 'products', 'section' => 'general']];
        if ($stringRevisions) {
            $data['expectedGrantRevision'] = (string) $data['expectedGrantRevision'];
            $data['expectedSourceRevision'] = (string) $data['expectedSourceRevision'];
        }
        $copy = app(MetafieldDefinitions::class)->import($data, OwnershipActor::firstOrFail());
        expect($copy->id)->not->toBe($source->id)->and($copy->catalog_source_id)->toBe($source->id)->and($copy->translations->first()->title)->toBe('Source schema');
        $snapshot = $catalog->inspect($grant->id);
        app(MetafieldDefinitions::class)->revoke($grant->id, $grant->revision, OwnershipActor::firstOrFail());
        expect(fn () => $copy->getConnection()->transaction(fn () => $catalog->persist($snapshot, $data)))->toThrow(OwnershipViolation::class);
        expect($copy->fresh())->not->toBeNull();
    });
    $runner->run(OwnershipCompany::findOrFail(8), $actor, function () use ($grant): void {
        expect(fn () => app(MetafieldOwnerCatalog::class)->inspect($grant->id))->toThrow(OwnershipViolation::class);
    });
    Event::assertDispatched(MetafieldDefinitionCatalogGrantAudited::class, static fn (MetafieldDefinitionCatalogGrantAudited $event): bool => $event->operation === 'granted' && $event->grantId === $grant->id && $event->ownerScopeKey !== '');
    Event::assertDispatched(MetafieldDefinitionCatalogGrantAudited::class, static fn (MetafieldDefinitionCatalogGrantAudited $event): bool => $event->operation === 'revoked' && $event->grantId === $grant->id && $event->ownerScopeKey !== '');
})->with([false, true]);
