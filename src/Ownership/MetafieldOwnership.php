<?php

declare(strict_types=1);

namespace Nvl\Metafields\Ownership;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;

/** Resolves canonical metadata without retaining request admission. @internal */
final class MetafieldOwnership
{
    public static function declared(): bool
    {
        return (app(OwnershipResourceRegistry::class)->all()['metafields.definitions'] ?? null)?->ownership->strategy !== null && app(OwnershipResourceRegistry::class)->get('metafields.definitions')->ownership->strategy !== 'unscoped';
    }

    public static function active(): bool
    {
        return self::declared() || config('nvl-tenancy.enabled') === true;
    }

    public static function resource(Model $model): string
    {
        return self::declared() ? app(OwnershipResourceRegistry::class)->forModel($model)->key : app(TenantResourceRegistry::class)->forModel($model)->key;
    }

    /** Capture scalar admission; untouched graphs preserve disabled installation behavior. */
    public static function envelope(): OwnershipJobEnvelope
    {
        $context = app(OwnershipContext::class);
        if ($context->snapshot()->mode === OwnershipContextMode::Unresolved) {
            foreach (app(OwnershipResourceRegistry::class)->all() as $resource) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    return OwnershipJobEnvelope::capture($context);
                }
            }

            return OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
        }

        return OwnershipJobEnvelope::capture($context);
    }

    private function __construct() {}
}
