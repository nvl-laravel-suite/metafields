<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Consumer-safe display contract for one owner assignment.
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class MetafieldDefinitionAssignmentPayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|null  $uiConfig
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $definitionId,
        #[LiteralTypeScriptType('string')]
        public readonly string $ownerType,
        #[LiteralTypeScriptType('string')]
        public readonly string $section,
        #[LiteralTypeScriptType('number')]
        public readonly int $displayOrder,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isRequired,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isActive,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
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
