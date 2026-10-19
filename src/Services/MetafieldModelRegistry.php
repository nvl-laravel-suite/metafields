<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Models\ConfiguredModelResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Resolves the one configured MetafieldDefinition extension through the shared native model identity.
 *
 * @api
 */
final class MetafieldModelRegistry
{
    /** @var class-string<MetafieldDefinition>|null */
    private ?string $definition = null;

    private bool $validated = false;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        private readonly Repository $configuration,
        private readonly OwnerRegistry $identities,
    ) {}

    /** Resolve and freeze the concrete MetafieldDefinition class before resource compilation.
     * @internal
     *
     * @return class-string<MetafieldDefinition>
     */
    public function prepare(): string
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $configured = $this->configuration->get('nvl-metafields.models.definition', MetafieldDefinition::class);
        if (! is_string($configured)) {
            throw new OwnershipConfigurationInvalid('MetafieldDefinition model configuration must name a concrete MetafieldDefinition subclass.');
        }
        $class = (new ConfiguredModelResolver)->resolve($configured, MetafieldDefinition::class);
        if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
            throw new OwnershipConfigurationInvalid('MetafieldDefinition roots require a native column or concrete primary pivot.');
        }
        $this->identities->registerReplacement('metafields.definitions', MetafieldDefinition::class, $class);
        $this->definition = $class;

        return $class;
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<MetafieldDefinition>
     */
    public function definitionClass(): string
    {
        $class = $this->prepare();
        if ($this->validated) {
            return $class;
        }
        $model = new $class;
        $base = new MetafieldDefinition;
        (new ConfiguredModelResolver)->assertOwnershipCapabilities($model, $base);
        $this->validated = true;

        return $class;
    }

    /** Resolve the immutable model from native framework entry points.
     * @internal
     *
     * @return class-string<MetafieldDefinition>
     */
    public static function modelClass(): string
    {
        return Container::getInstance()->make(self::class)->definitionClass();
    }

    /** Construct the configured root.
     * @internal
     *
     * @param  array<string,mixed>  $attributes
     */
    public static function newInstance(array $attributes = []): MetafieldDefinition
    {
        $class = Container::getInstance()->make(self::class)->definitionClass();

        return new $class($attributes);
    }

    /** Persist a package-authored attribute map under the exact source assignment.
     * @internal
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes): MetafieldDefinition
    {
        return app(OwnershipWriter::class)->create(self::modelClass(), static function (array $ownership) use ($attributes): MetafieldDefinition {
            $model = self::newInstance();
            $model->forceFill([...$attributes, ...$ownership])->save();

            return $model;
        });
    }

    /** Create the exact configured query.
     * @internal
     *
     * @return Builder<MetafieldDefinition>
     */
    public static function query(): Builder
    {
        return self::newInstance()->newQuery();
    }
}
