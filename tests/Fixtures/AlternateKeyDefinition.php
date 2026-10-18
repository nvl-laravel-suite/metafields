<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Metafields\Models\MetafieldDefinition;

/** Invalid storage extension used to prove native key validation. */
final class AlternateKeyDefinition extends MetafieldDefinition
{
    protected $primaryKey = 'alternate_id';
}
