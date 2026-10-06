<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionTenantGrant;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/**
 * Builds MetafieldDefinitionTenantGrant fixture rows and their declared package parents.
 *
 * @extends Factory<MetafieldDefinitionTenantGrant>
 *
 * @api
 */
final class MetafieldDefinitionTenantGrantFactory extends Factory
{
    protected $model = MetafieldDefinitionTenantGrant::class;

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
        })->afterMaking(function (MetafieldDefinitionTenantGrant $model) use (&$expandRelationships): void {
            if (! $expandRelationships) {
                return;
            }

            if ($model->getAttribute('definition_id') !== null) {
                $parent = MetafieldDefinition::query()->findOrFail(FactoryGuard::identifier($model->getAttribute('definition_id')));
                FactoryGuard::parent($parent, $model);
                if ($model->source_revision !== $parent->revision) {
                    throw new InvalidArgumentException('Fixture source revisions must match their exact persisted parent.');
                }
                if ($parent->tenant_id !== null || $parent->ownership_key !== 'platform') {
                    throw new InvalidArgumentException('Catalog fixtures require a platform-owned source.');
                }
            }
            $recipient = $model->getAttribute('tenant_id');
            if (! is_string($recipient)) {
                throw new InvalidArgumentException('Catalog fixtures require an explicit recipient tenant.');
            }
            FactoryGuard::recipient(new TenantId($recipient));
        });
    }

    /**
     * Define the fixture's persisted attributes.
     *
     * @return array<model-property<MetafieldDefinitionTenantGrant>, mixed>
     */
    public function definition(): array
    {
        return [
            'definition_id' => MetafieldDefinition::factory(),
            'tenant_id' => null,
            'source_revision' => fn (array $attributes): int => $attributes['definition_id'] === null ? 1 : MetafieldDefinition::query()->findOrFail(FactoryGuard::identifier($attributes['definition_id']))->revision,
            'revision' => 1,
            'enabled' => true,
        ];
    }

    /**
     * Associate an admitted persisted MetafieldDefinition parent.
     *
     * @api
     */
    public function forDefinition(MetafieldDefinition $parent): static
    {
        FactoryGuard::parent($parent, new MetafieldDefinitionTenantGrant);

        return $this->state([
            'definition_id' => $parent->getKey(),
            'source_revision' => $parent->revision,
        ]);
    }

    /** Associate an active recipient in the explicit platform context.
     *
     * @api
     */
    public function forRecipient(TenantId $recipient): static
    {
        FactoryGuard::recipient($recipient);

        return $this->state(['tenant_id' => $recipient->value]);
    }
}
