<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\Fixtures\PivotOwnershipDefinition;
use Nvl\Metafields\Tests\OwnershipPivotTestCase;
use Nvl\Ownership\Services\OwnershipRunner;

uses(OwnershipPivotTestCase::class);
it('resolves exclusive native pivots and prevents raw primary detach or reassignment', function (): void {
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail($id), OwnershipActor::firstOrFail(), function (): void {
            $definition = OwnershipMetafieldScenario::definition('pivot');
            expect($definition::class)->toBe(PivotOwnershipDefinition::class)->and(MetafieldModelRegistry::query()->count())->toBe(1);
            $connection = $definition->getConnection();
            expect(fn () => $connection->transaction(fn () => $connection->table('metafield_definition_owners')->where('definition_id', $definition->id)->delete()))->toThrow(QueryException::class);
        });
    }
});
