<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use LogicException;
use Nvl\Data\Traits\DataTransform;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class MetafieldDefinitionSettings extends Data
{
    use DataTransform;

    /**
     * @param  string[]|null  $validationRules
     * @param  DataCollection<int, MetafieldJsonProperty>|null  $jsonPropertySchema
     */
    public function __construct(
        public readonly string $id,
        public readonly string $handle,
        public readonly string $namespace,
        public readonly string $key,
        public readonly MetafieldTypeEnum $type,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $hint,
        public readonly ?string $referencedModelType,
        public readonly bool $isTranslatable,
        public readonly bool $isRequired,
        public readonly bool $isFilterable,
        public readonly ?array $validationRules,
        #[DataCollectionOf(MetafieldJsonProperty::class)]
        public readonly ?DataCollection $jsonPropertySchema,
        public readonly mixed $defaultValue,
        public readonly int $displayOrder,
        public readonly MetafieldDefinitionAssignmentPayload $assignment,
    ) {}

    public static function fromModel(MetafieldDefinition $definition): self
    {
        $definition->loadMissing('assignments');

        return new self(
            id: $definition->id,
            handle: $definition->handle,
            namespace: $definition->namespace,
            key: $definition->key,
            type: $definition->type,
            title: $definition->displayTitle(),
            description: $definition->displayDescription(),
            hint: $definition->displayHint(),
            referencedModelType: $definition->referenced_model_type,
            isTranslatable: $definition->is_translatable,
            isRequired: $definition->is_required,
            isFilterable: $definition->is_filterable,
            validationRules: $definition->validation_rules,
            jsonPropertySchema: is_array($definition->json_property_schema)
                ? MetafieldJsonProperty::collect($definition->json_property_schema, DataCollection::class)
                : null,
            defaultValue: $definition->getSerializableDefaultValue(),
            displayOrder: $definition->display_order,
            assignment: self::resolveAssignment($definition),
        );
    }

    private static function resolveAssignment(MetafieldDefinition $definition): MetafieldDefinitionAssignmentPayload
    {
        $assignment = $definition->assignments->first();

        if ($assignment === null) {
            throw new LogicException("Metafield definition [{$definition->handle}] must have one assignment.");
        }

        return MetafieldDefinitionAssignmentPayload::fromModel($assignment);
    }
}
