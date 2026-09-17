<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions;

use Illuminate\Support\Facades\DB;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;

/** Revokes future definition copies without changing committed tenant schemas. */
final readonly class RevokeMetafieldDefinitionTenantGrantAction
{
    /** Create the platform revocation action. */
    public function __construct(private TenantContext $context) {}

    /** Revoke one exact grant revision. */
    public function execute(string $grantId, int $expectedRevision): MetafieldDefinitionTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform) {
            throw new TenantBoundaryViolation('Metafield grant revocation requires platform context.');
        }

        return DB::transaction(function () use ($grantId, $expectedRevision): MetafieldDefinitionTenantGrant {
            $identity = MetafieldDefinitionTenantGrant::withoutGlobalScope('tenant')->whereKey($grantId)->first(['tenant_id', 'definition_id']);
            if (! $identity instanceof MetafieldDefinitionTenantGrant) {
                throw new TenantBoundaryViolation('The Metafield grant revision is unavailable.');
            }
            DB::table(MetafieldsTables::TenantGrantLocks)->insertOrIgnore([
                'tenant_id' => $identity->tenant_id,
                'definition_id' => $identity->definition_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table(MetafieldsTables::TenantGrantLocks)
                ->where('tenant_id', $identity->tenant_id)
                ->where('definition_id', $identity->definition_id)
                ->lockForUpdate()->first();
            $grant = MetafieldDefinitionTenantGrant::withoutGlobalScope('tenant')->whereKey($grantId)->lockForUpdate()->first();
            if (! $grant instanceof MetafieldDefinitionTenantGrant || ! $grant->enabled || $grant->revision !== $expectedRevision) {
                throw new TenantBoundaryViolation('The Metafield grant revision is unavailable.');
            }
            $grant->forceFill([
                'enabled' => false,
                'revoked_at' => now(),
                'revision' => $grant->revision + 1,
            ])->save();
            MetafieldDefinitionCatalogGrantAudited::dispatch(
                'revoked', $grant->id, $grant->tenant_id, $grant->definition_id, $grant->source_revision, $grant->revision,
            );

            return $grant->refresh();
        });
    }
}
