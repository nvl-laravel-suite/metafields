<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Tests\OwnershipTestCase;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\ValueObjects\OwnershipOperation;

/** Keeps definitions ordinary beside an independently adopted native host. */
class MixedMetafieldOwnershipTestCase extends OwnershipTestCase
{
    protected function definitionClass(): string
    {
        return MetafieldDefinition::class;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('nvl-metafields.owners', []);
        $app['config']->set('nvl-metafields.ownership.owner_resources', []);
    }

    protected function adoptOwnership(): void
    {
        $operation = new OwnershipOperation('adopt-host-only', 'operator', '1');
        $coordinator = app(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['fixture'], [], $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }
}
