<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Support\Models\ConfiguredModelRegistration;
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
    /** @var ConfiguredModelRegistration<MetafieldDefinition> */
    private readonly ConfiguredModelRegistration $registration;

    /** Retain deployment configuration and the shared identity registry. */
    public function __construct(
        Repository $configuration,
        OwnerRegistry $identities,
    ) {
        $this->registration = new ConfiguredModelRegistration(
            $configuration,
            $identities,
            'nvl-metafields.models.definition',
            MetafieldDefinition::class,
            'metafields.definitions',
            static function (string $class): void {
                if (! in_array($class::ownershipDefinition()->strategy, ['unscoped', 'column', 'pivot'], true)) {
                    throw new OwnershipConfigurationInvalid('MetafieldDefinition roots require a native column or concrete primary pivot.');
                }
            },
        );
    }

    /** Resolve and freeze the concrete MetafieldDefinition class before resource compilation.
     * @internal
     *
     * @return class-string<MetafieldDefinition>
     */
    public function prepare(): string
    {
        return $this->registration->prepare();
    }

    /** Validate native capabilities after Laravel installs Eloquent's dispatcher.
     * @return class-string<MetafieldDefinition>
     */
    public function definitionClass(): string
    {
        return $this->registration->modelClass();
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
