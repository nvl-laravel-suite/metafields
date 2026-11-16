<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

/**
 * @internal
 *
 * @phpstan-import-type DefinitionAssignment from MetafieldDefinitionInput
 */
final class MetafieldDefinitionAssignmentSyncer
{
    public function __construct(private OwnershipWriter $ownershipWriter) {}

    /** @param DefinitionAssignment $assignment */
    public function sync(
        MetafieldDefinition $definition,
        array $assignment,
    ): void {
        $ownerType = $assignment['ownerType'];
        $uiConfig = $assignment['uiConfig'] ?? null;
        $definitionAssignment = $this->current($definition, $ownerType)
            ?? new MetafieldDefinitionAssignment(['definition_id' => $definition->id, 'owner_type' => $ownerType]);

        $definitionAssignment->fill([
            'section' => $assignment['section'],
            'display_order' => ($assignment['displayOrder'] ?? 0),
            'is_required' => ($assignment['isRequired'] ?? false),
            'is_active' => ($assignment['isActive'] ?? true),
            'ui_config' => $uiConfig,
            ...(array_key_exists('ownerScopes', $assignment) ? ['owner_scopes' => $assignment['ownerScopes']] : []),
        ]);
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

    /**
     * Return the active assignment for an owner type, or else its newest deleted generation.
     *
     * Adopted history can keep several deleted generations beside the active one, so the choice must be deterministic.
     */
    private function current(MetafieldDefinition $definition, string $ownerType): ?MetafieldDefinitionAssignment
    {
        return $definition->assignments()
            ->withTrashed()
            ->reorder()
            ->where('owner_type', $ownerType)
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('deleted_at')
            ->orderByDesc('id')
            ->first();
    }
}
