<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;

/**
 * Builds MetafieldTranslation fixture rows and their declared package parents.
 *
 * @extends Factory<MetafieldTranslation>
 *
 * @api
 */
final class MetafieldTranslationFactory extends Factory
{
    protected $model = MetafieldTranslation::class;

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
        })->afterMaking(function (MetafieldTranslation $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('metafield_id') !== null) {
                $parent = Metafield::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('metafield_id')));
                FactoryGuard::parent($parent, $model);
                FactoryGuard::inherit($model, $parent);
                if (! $parent->definition->is_translatable || ! $parent->definition->type->supportsTranslations()) {
                    throw new InvalidArgumentException('Value translation fixtures require a translatable definition.');
                }
            }
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MetafieldTranslation>, mixed>
     */
    public function definition(): array
    {
        return [
            'metafield_id' => Metafield::factory()->state(['definition_id' => MetafieldDefinition::factory()->translatable(), 'value' => null]),
            'locale' => 'en',
            'value' => $this->faker->word(),
        ];
    }

    /**
     * Associate an admitted persisted Metafield parent.
     *
     * @api
     */
    public function forMetafield(Metafield $parent): static
    {
        FactoryGuard::parent($parent, new MetafieldTranslation);

        return $this->state([
            'metafield_id' => $parent->getKey(),
        ]);
    }

    /** Build an owner value parent with a translatable definition.
     *
     * @api
     */
    public function forOwner(Model $owner): static
    {
        return $this->state([
            'metafield_id' => Metafield::factory()->forOwner($owner)->state([
                'definition_id' => MetafieldDefinition::factory()->translatable(),
                'value' => null,
            ]),
        ]);
    }
}
