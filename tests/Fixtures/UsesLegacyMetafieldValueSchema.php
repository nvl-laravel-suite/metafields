<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Models\Metafield;
use Orchestra\Testbench\Attributes\ResetRefreshDatabaseState;

/** Isolate intentionally corrupt legacy storage from the ordinary unique owner fixture. */
trait UsesLegacyMetafieldValueSchema
{
    /** Force this fixture's schema callback even after ordinary native test transactions. */
    protected function beforeRefreshingDatabase(): void
    {
        parent::beforeRefreshingDatabase();
        ResetRefreshDatabaseState::run();
    }

    /** Leave the next test class responsible for restoring its ordinary unique schema. */
    protected function tearDownUsesLegacyMetafieldValueSchema(): void
    {
        ResetRefreshDatabaseState::run();
    }

    /** Create legacy storage before RefreshDatabase starts its transaction. */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        parent::defineDatabaseMigrationsAfterDatabaseRefreshed();
        Schema::table((new Metafield)->getTable(), static fn (Blueprint $table) => $table->dropUnique('metafields_owner_definition_unique'));
    }
}
