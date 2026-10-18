<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Data\AssignMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Spatie\LaravelData\Optional;

final class MetafieldDefinitionAssignmentSyncer
{
    public function __construct(private OwnershipWriter $ownershipWriter) {}

    public function sync(
        MetafieldDefinition $definition,
        AssignMetafieldDefinitionPayload $assignment,
    ): void {
        $ownerType = $assignment->ownerType;
        $uiConfig = $assignment->uiConfig instanceof Optional ? null : $assignment->uiConfig;
        $definitionAssignment = $definition->assignments()
            ->withTrashed()
            ->firstOrNew(['owner_type' => $ownerType]);

        $definitionAssignment->fill([
            'section' => $assignment->section,
            'display_order' => $assignment->displayOrder,
            'is_required' => $assignment->isRequired,
            'is_active' => $assignment->isActive,
            'ui_config' => $uiConfig,
        ]);
        if (config('nvl-tenancy.enabled') === true) {
            $definitionAssignment->forceFill(array_filter([
                'tenant_id' => $definition->getAttribute('tenant_id'),
                'ownership_key' => $definition->getAttribute('ownership_key'),
            ], static fn (mixed $value): bool => $value !== null));
        }
        if ($definitionAssignment->exists) {
            $definitionAssignment->save();
        } else {
            $this->ownershipWriter->create($definitionAssignment::class, static function (array $ownership) use ($definitionAssignment): MetafieldDefinitionAssignment {
                $definitionAssignment->forceFill($ownership)->save();

                return $definitionAssignment;
            });
        }

        if ($definitionAssignment->trashed()) {
            $definitionAssignment->restore();
        }

        $definition->assignments()
            ->where('owner_type', '!=', $ownerType)
            ->get()
            ->each
            ->delete();
    }
}
