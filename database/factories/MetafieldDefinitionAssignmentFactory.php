<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;

/**
 * Builds native package fixture rows and declared parents.
 *
 * @api
 *
 * @extends Factory<MetafieldDefinitionAssignment>
 */
final class MetafieldDefinitionAssignmentFactory extends Factory
{
    protected $model = MetafieldDefinitionAssignment::class;

    /**
     * Prepare native parent and owner facts after Laravel expands relationships.
     *
     * @internal
     */
    public function configure(): static
    {
        $expandRelationships = true;

        return $this->state(function () use (&$expandRelationships): array {
            $expandRelationships = $this->expandRelationships;

            return [];
        })->afterMaking(function (MetafieldDefinitionAssignment $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $ownerType = $model->getAttribute('owner_type');
            if (! is_string($ownerType) || $ownerType === '') {
                throw new InvalidArgumentException('Definition assignment fixtures require forOwnerType() with a native registered owner type.');
            }
            $configuration = Container::getInstance()->make(MetafieldOwnerRegistry::class)->configurationForType($ownerType);
            if ($configuration['runtime_status'] !== 'live' || ! in_array($model->section, $configuration['sections'], true)) {
                throw new InvalidArgumentException('Definition assignment fixtures require a live owner section.');
            }
            if ($model->getAttribute('definition_id') !== null) {
                $parent = MetafieldDefinition::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('definition_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent, true);
            }
        });
    }

    /**
     * @return array<model-property<MetafieldDefinitionAssignment>, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => MetafieldDefinition::factory(),
            'owner_type' => null,
            'section' => 'general',
            'display_order' => 0,
            'is_required' => false,
            'is_active' => true,
        ];
    }

    /** Apply the forOwnerType fixture state.
     *
     * @api
     */
    public function forOwnerType(string|BackedEnum $ownerType): self
    {
        return $this->state(fn (): array => [
            'owner_type' => $ownerType instanceof BackedEnum ? (string) $ownerType->value : $ownerType,
        ]);
    }

    /** Apply the forDefinition fixture state.
     *
     * @api
     */
    public function forDefinition(MetafieldDefinition $definition): self
    {
        FactoryGuard::parent($definition, new MetafieldDefinitionAssignment);

        return $this->state(fn (): array => [
            'definition_id' => $definition->id,
        ]);
    }

    /** Apply the required fixture state.
     *
     * @api
     */
    public function required(): self
    {
        return $this->state(fn (): array => [
            'is_required' => true,
        ]);
    }

    /** Apply the inactive fixture state.
     *
     * @api
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    /** Apply the inSection fixture state.
     *
     * @api
     */
    public function inSection(string $section): self
    {
        return $this->state(fn (): array => [
            'section' => $section,
        ]);
    }
}
