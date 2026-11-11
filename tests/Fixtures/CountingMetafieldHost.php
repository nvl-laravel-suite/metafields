<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Traits\HasMetafields;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Unowned host that counts how often its ownership declaration is read. */
final class CountingMetafieldHost extends Model implements Ownable
{
    use HasMetafields;
    use HasOwnership;

    public static int $declarationReads = 0;

    protected $table = 'test_metafield_owners';

    protected $guarded = [];

    public static function ownershipDefinition(): OwnershipDefinition
    {
        self::$declarationReads++;

        return OwnershipDefinition::unscoped();
    }
}
