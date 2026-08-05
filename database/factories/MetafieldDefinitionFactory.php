<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\MetafieldDefinition;

/**
 * @extends Factory<MetafieldDefinition>
 */
final class MetafieldDefinitionFactory extends Factory
{
    protected $model = MetafieldDefinition::class;

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
        ];
    }

    public function translatable(): self
    {
        return $this->state(fn (): array => [
            'is_translatable' => true,
        ]);
    }

    public function required(): self
    {
        return $this->state(fn (): array => [
            'is_required' => true,
        ]);
    }

    public function filterable(): self
    {
        return $this->state(fn (): array => [
            'is_filterable' => true,
        ]);
    }

    public function ofType(MetafieldTypeEnum $type): self
    {
        return $this->state(fn (): array => [
            'type' => $type,
        ]);
    }

    public function withDefaultValue(mixed $value): self
    {
        return $this->afterCreating(function (MetafieldDefinition $definition) use ($value): void {
            $definition->setDefaultValue($value);
            $definition->save();
        });
    }

    /**
     * @param  array<int, array{key: string, type: string, isRequired: bool}>  $schema
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
     */
    public function withValidationRules(array $rules): self
    {
        return $this->state(fn (): array => [
            'validation_rules' => $rules,
        ]);
    }
}
