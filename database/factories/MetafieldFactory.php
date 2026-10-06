<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Builds native package fixture rows and declared parents.
 *
 * @api
 *
 * @extends Factory<Metafield>
 */
final class MetafieldFactory extends Factory
{
    protected $model = Metafield::class;

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
        })->afterMaking(function (Metafield $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            $owner = FactoryGuard::owner($model, 'metafieldable_type', 'metafieldable_id');
            FactoryGuard::inherit($model, $owner);
            if ($model->getAttribute('definition_id') !== null) {
                $parent = MetafieldDefinition::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('definition_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
            }
        });
    }

    /**
     * @return array<model-property<Metafield>, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => MetafieldDefinition::factory(),
            'metafieldable_id' => null,
            'metafieldable_type' => null,
            'value' => $this->faker->word(),
        ];
    }

    /** Apply the forDefinition fixture state.
     *
     * @api
     */
    public function forDefinition(MetafieldDefinition $definition): self
    {
        FactoryGuard::parent($definition, new Metafield);

        return $this->state(fn (): array => [
            'definition_id' => $definition->id,
        ]);
    }

    /**
     * @api
     */
    public function forOwner(Model $owner): static
    {
        FactoryGuard::parent($owner, new Metafield);

        return $this->state([
            'metafieldable_id' => (string) FactoryGuard::identifier($owner->getKey()),
            'metafieldable_type' => $owner->getMorphClass(),
        ]);
    }

    /** Apply the withValue fixture state.
     *
     * @api
     */
    public function withValue(mixed $value): self
    {
        return $this->state(fn (): array => [
            'value' => is_scalar($value) ? (string) $value : json_encode($value),
        ]);
    }
}
