<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Events\MetafieldDefinitionCatalogGrantAudited;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Tenancy\Contracts\TenantContext;
use Nvl\Tenancy\Contracts\TenantDirectory;
use Nvl\Tenancy\Enums\TenantContextMode;
use Nvl\Tenancy\Enums\TenantStatus;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Grants one exact platform definition revision to an active tenant. */
final readonly class GrantMetafieldDefinitionToTenantAction
{
    /** Create the platform grant action. */
    public function __construct(private TenantContext $context, private TenantDirectory $directory) {}

    /** Grant or refresh the exact platform source revision. */
    public function execute(string $definitionId, TenantId $recipient, int $sourceRevision): MetafieldDefinitionTenantGrant
    {
        if ($this->context->snapshot()->mode !== TenantContextMode::Platform
            || $this->directory->find($recipient)->status !== TenantStatus::Active) {
            throw new TenantBoundaryViolation('Metafield grants require platform context and an active recipient.');
        }

        return DB::transaction(function () use ($definitionId, $recipient, $sourceRevision): MetafieldDefinitionTenantGrant {
            DB::table(MetafieldsTables::TenantGrantLocks)->insertOrIgnore([
                'tenant_id' => $recipient->value,
                'definition_id' => $definitionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table(MetafieldsTables::TenantGrantLocks)
                ->where('tenant_id', $recipient->value)
                ->where('definition_id', $definitionId)
                ->lockForUpdate()->first();
            $source = MetafieldDefinition::withoutGlobalScope('tenant')
                ->whereKey($definitionId)
                ->whereNull('tenant_id')
                ->where('ownership_key', 'platform')
                ->whereNotNull('active_handle')
                ->lockForUpdate()->first();
            if (! $source instanceof MetafieldDefinition || $source->revision !== $sourceRevision) {
                throw new TenantBoundaryViolation('The platform Metafield definition revision is unavailable.');
            }
            $grant = MetafieldDefinitionTenantGrant::withoutGlobalScope('tenant')
                ->where('tenant_id', $recipient->value)
                ->where('definition_id', $source->id)
                ->lockForUpdate()->first();
            $operation = 'created';
            if ($grant instanceof MetafieldDefinitionTenantGrant) {
                $grant->forceFill([
                    'source_revision' => $sourceRevision,
                    'revision' => $grant->revision + 1,
                    'enabled' => true,
                    'revoked_at' => null,
                ])->save();
                $operation = 'refreshed';
            } else {
                $grant = MetafieldDefinitionTenantGrant::withoutGlobalScope('tenant')->forceCreate([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $recipient->value,
                    'definition_id' => $source->id,
                    'source_revision' => $sourceRevision,
                    'revision' => 1,
                    'enabled' => true,
                ]);
            }
            MetafieldDefinitionCatalogGrantAudited::dispatch(
                $operation, $grant->id, $recipient->value, $source->id, $sourceRevision, $grant->revision,
            );

            return $grant->refresh();
        });
    }
}
