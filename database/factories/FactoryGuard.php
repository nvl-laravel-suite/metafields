<?php

declare(strict_types=1);

namespace Nvl\Metafields\Database\Factories;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;

/** Checks native fixture identities against the declared ownership graph. @internal */
final class FactoryGuard
{
    public static function identifier(mixed $key): int|string
    {
        if ((! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('Factory identities require a non-empty native scalar identifier.');
        }

        return $key;
    }

    public static function parent(Model $parent, Model $child): void
    {
        $key = $parent->getKey();
        if (! $parent->exists || (! is_string($key) && ! is_int($key)) || $key === ''
            || $parent->getRawOriginal($parent->getKeyName()) !== $key
            || $parent->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory parents require an unchanged persisted identity on the fixture connection.');
        }
        if (MetafieldOwnership::active()) {
            Container::getInstance()->make(OwnershipBoundary::class)->assertRecord($parent, MetafieldOwnership::resource($parent));
        }
    }

    public static function owner(Model $child, string $typeColumn, string $idColumn): Model
    {
        $type = $child->getAttribute($typeColumn);
        $key = $child->getAttribute($idColumn);
        if (! is_string($type) || $type === '' || (! is_string($key) && ! is_int($key)) || $key === '') {
            throw new InvalidArgumentException('This factory requires a persisted registered owner.');
        }
        $class = Relation::getMorphedModel($type) ?? $type;
        if (! is_a($class, Model::class, true) || $class === Model::class) {
            throw new InvalidArgumentException('Factory ownership requires a concrete native morph model.');
        }
        $prototype = new $class;
        if ($prototype->getConnection() !== $child->getConnection()) {
            throw new InvalidArgumentException('Factory owners require the fixture connection.');
        }
        $owner = $prototype->newQuery()->findOrFail($key);
        self::parent($owner, $child);
        if ($owner->getMorphClass() !== $type) {
            throw new InvalidArgumentException('Factory owners require their native morph identity.');
        }

        return $owner;
    }

    public static function root(Model $model, string $resource): void
    {
        if (MetafieldOwnership::active()) {
            $model->forceFill(Container::getInstance()->make(OwnershipBoundary::class)->attributes($resource));
        }
    }

    public static function inherit(Model $model, Model $parent, bool $withOwnershipKey = false): void
    {
        self::parent($parent, $model);
    }

    private function __construct() {}
}
