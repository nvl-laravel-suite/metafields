<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipQueueCapture;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/**
 * Resolves canonical metadata without retaining request admission.
 *
 * @internal
 */
final class MetafieldOwnership
{
    public static function declared(): bool
    {
        $resources = app(OwnershipResourceRegistry::class)->all();
        $owned = isset($resources['metafields.definitions']) && $resources['metafields.definitions']->ownership->strategy !== 'unscoped';
        self::assertCompatibleHosts($owned);

        return $owned;
    }

    /** Reject a globally scoped value graph before an owned host can contribute data. */
    public static function assertCompatibleHosts(bool $owned): void
    {
        if (! $owned) {
            if (config('nvl-metafields.ownership.owner_resources', []) !== []) {
                throw new OwnershipConfigurationInvalid('Native Metafield host resources require an owned definition root.');
            }
            if (app(MetafieldOwnerRegistry::class)->hasOwnedHost(app(OwnershipResourceRegistry::class)->all())) {
                throw new OwnershipConfigurationInvalid('Owned Metafield hosts require an owned definition root and inherited values.');
            }
        }
    }

    public static function active(): bool
    {
        return self::declared();
    }

    public static function resource(Model $model): string
    {
        return app(OwnershipResourceRegistry::class)->forModel($model)->key;
    }

    /** Capture scalar admission; untouched graphs preserve disabled installation behavior. */
    public static function envelope(): OwnershipJobEnvelope
    {
        return app(OwnershipQueueCapture::class)->capture();
    }

    private function __construct() {}
}
