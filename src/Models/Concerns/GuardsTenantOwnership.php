<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models\Concerns;

use Nvl\Support\Ownership\Compatibility\LegacyModelOwnership;

/** Prevents persisted Metafield tenant partition identity from changing. */
trait GuardsTenantOwnership
{
    /** Register immutable ownership enforcement for updates. */
    protected static function bootGuardsTenantOwnership(): void
    {
        static::updating(static function (self $model): void {
            LegacyModelOwnership::assertImmutable($model, [
                'tenant_id',
                'ownership_key',
                'catalog_import_key',
                'catalog_source_id',
                'catalog_source_revision',
                'catalog_source_hash',
                'catalog_import_request_hash',
            ], 'Metafield');
        });
    }
}
