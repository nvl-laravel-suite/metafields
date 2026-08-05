<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use BackedEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;

/**
 * @extends Factory<MetafieldDefinitionAssignment>
 */
final class MetafieldDefinitionAssignmentFactory extends Factory
{
    protected $model = MetafieldDefinitionAssignment::class;

    /**
     * @return array<model-property<MetafieldDefinitionAssignment>, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => MetafieldDefinition::factory(),
            'owner_type' => 'default',
            'section' => 'general',
            'display_order' => 0,
            'is_required' => false,
            'is_active' => true,
        ];
    }

    public function forOwnerType(string|BackedEnum $ownerType): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $ownerType instanceof BackedEnum ? (string) $ownerType->value : $ownerType,
        ]);
    }

    public function forDefinition(MetafieldDefinition $definition): self
    {
        return $this->state(fn (): array => [
            'definition_id' => $definition->id,
        ]);
    }

    public function required(): self
    {
        return $this->state(fn (): array => [
            'is_required' => true,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function inSection(string $section): self
    {
        return $this->state(fn (): array => [
            'section' => $section,
        ]);
    }
}
