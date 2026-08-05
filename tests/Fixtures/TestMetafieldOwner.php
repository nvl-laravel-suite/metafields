<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Traits\HasMetafields;

final class TestMetafieldOwner extends Model
{
    use HasMetafields;

    protected $table = 'test_metafield_owners';

    protected $guarded = [];
}
