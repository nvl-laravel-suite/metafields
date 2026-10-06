<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;

/**
 * Builds MetafieldDefinitionTranslation fixture rows and their declared package parents.
 *
 * @extends Factory<MetafieldDefinitionTranslation>
 *
 * @api
 */
final class MetafieldDefinitionTranslationFactory extends Factory
{
    protected $model = MetafieldDefinitionTranslation::class;

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
        })->afterMaking(function (MetafieldDefinitionTranslation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('metafield_definition_id') !== null) {
                $parent = MetafieldDefinition::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('metafield_definition_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent, true);
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MetafieldDefinitionTranslation>, mixed>
     */
    public function definition(): array
    {
        return [
            'metafield_definition_id' => MetafieldDefinition::factory(),
            'locale' => 'en',
            'title' => $this->faker->sentence(3),
        ];
    }

    /**
     * Associate an admitted persisted MetafieldDefinition parent.
     *
     * @api
     */
    public function forDefinition(MetafieldDefinition $parent): static
    {
        FactoryGuard::parent($parent, new MetafieldDefinitionTranslation);

        return $this->state([
            'metafield_definition_id' => $parent->getKey(),
        ]);
    }
}
