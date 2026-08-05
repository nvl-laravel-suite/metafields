<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * @extends Factory<Metafield>
 */
final class MetafieldFactory extends Factory
{
    protected $model = Metafield::class;

    /**
     * @return array<model-property<Metafield>, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => MetafieldDefinition::factory(),
            'metafieldable_id' => (string) Str::uuid(),
            'metafieldable_type' => Model::class,
            'value' => $this->faker->word(),
        ];
    }

    public function forDefinition(MetafieldDefinition $definition): self
    {
        return $this->state(fn (): array => [
            'definition_id' => $definition->id,
        ]);
    }

    /**
     * @param  Model  $owner
     */
    public function forOwner($owner): self
    {
        $ownerKey = $owner->getKey();

        if (! is_string($ownerKey) && ! is_int($ownerKey)) {
            throw new \InvalidArgumentException('Metafield factory owners require a string or integer identifier.');
        }

        return $this->state(fn (): array => [
            'metafieldable_id' => (string) $ownerKey,
            'metafieldable_type' => $owner->getMorphClass(),
        ]);
    }

    public function withValue(mixed $value): self
    {
        return $this->state(fn (): array => [
            'value' => is_scalar($value) ? (string) $value : json_encode($value),
        ]);
    }
}
