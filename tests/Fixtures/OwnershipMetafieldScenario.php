<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

/** Builds actual host and definition rows through admitted package workflows. */
final class OwnershipMetafieldScenario
{
    public static function owner(): OwnershipMetafieldOwner
    {
        return app(OwnershipWriter::class)->create(OwnershipMetafieldOwner::class, static function (OwnershipAssignment $a): OwnershipMetafieldOwner {
            $m = new OwnershipMetafieldOwner;
            $m->forceFill([...$a->attributes(), 'name' => 'Product'])->save();

            return $m;
        });
    }

    public static function definition(string $key = 'text', string $type = 'string'): MetafieldDefinition
    {
        return app(MetafieldDefinitions::class)->create(['namespace' => 'product', 'key' => $key, 'type' => $type, 'referencedModelType' => in_array($type, ['reference', 'reference_list'], true) ? 'products' : null, 'assignment' => ['ownerType' => 'products', 'section' => 'general'], 'translations' => ['en' => ['title' => 'Field']]], OwnershipActor::firstOrFail());
    }
}
