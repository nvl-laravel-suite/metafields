<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * Builds native package fixture rows and declared parents.
 *
 * @api
 *
 * @extends Factory<MetafieldDefinition>
 */
final class MetafieldDefinitionFactory extends Factory
{
    protected $model = MetafieldDefinition::class;

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
        })->afterMaking(function (MetafieldDefinition $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            FactoryGuard::root($model, 'metafields.definitions');
        });
    }

    /**
     * @return array<model-property<MetafieldDefinition>, mixed>
     */
    public function definition(): array
    {
        $namespace = $this->faker->randomElement(['details', 'seo', 'shipping', 'custom']);
        $key = $this->faker->unique()->slug(2);

        return [
            'namespace' => $namespace,
            'key' => $key,
            'type' => MetafieldTypeEnum::String,
            'is_translatable' => false,
            'is_required' => false,
            'is_filterable' => false,
            'display_order' => 0,
            'revision' => 1,
        ];
    }

    /** Apply the translatable fixture state.
     *
     * @api
     */
    public function translatable(): self
    {
        return $this->state(fn (): array => [
            'is_translatable' => true,
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

    /** Apply the filterable fixture state.
     *
     * @api
     */
    public function filterable(): self
    {
        return $this->state(fn (): array => [
            'is_filterable' => true,
        ]);
    }

    /** Apply the ofType fixture state.
     *
     * @api
     */
    public function ofType(MetafieldTypeEnum $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
        ]);
    }

    /** Apply the withDefaultValue fixture state.
     *
     * @api
     */
    public function withDefaultValue(mixed $value): self
    {
        return $this->afterCreating(function (MetafieldDefinition $definition) use ($value): void {
            $definition->setDefaultValue($value);
            $definition->save();
        });
    }

    /**
     * @param  array<int, array{key: string, type: string, isRequired: bool}>  $schema
     *
     * @api
     */
    public function withJsonPropertySchema(array $schema): self
    {
        return $this->state(fn (): array => [
            'type' => MetafieldTypeEnum::Json,
            'json_property_schema' => $schema,
        ]);
    }

    /**
     * @param  list<string>  $rules
     *
     * @api
     */
    public function withValidationRules(array $rules): self
    {
        return $this->state(fn (): array => [
            'validation_rules' => $rules,
        ]);
    }
}
