<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Consumer-safe display contract for one owner assignment.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class MetafieldDefinitionAssignmentPayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|null  $uiConfig
     */
    public function __construct(
        public readonly string $definitionId,
        public readonly string $ownerType,
        public readonly string $section,
        public readonly int $displayOrder,
        public readonly bool $isRequired,
        public readonly bool $isActive,
        public readonly ?array $uiConfig,
    ) {}

    public static function fromModel(MetafieldDefinitionAssignment $assignment): self
    {
        return new self(
            definitionId: $assignment->definition_id,
            ownerType: $assignment->owner_type,
            section: $assignment->section ?? 'general',
            displayOrder: $assignment->display_order,
            isRequired: $assignment->is_required,
            isActive: $assignment->is_active,
            uiConfig: $assignment->ui_config,
        );
    }
}
