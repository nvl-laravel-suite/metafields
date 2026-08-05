<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Data\AssignMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;
use Spatie\LaravelData\Optional;

final class MetafieldDefinitionAssignmentSyncer
{
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
        $definitionAssignment->save();

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
