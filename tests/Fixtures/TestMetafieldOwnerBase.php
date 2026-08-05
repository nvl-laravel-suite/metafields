<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Base model used to prove ambiguous owner inheritance is rejected. */
class TestMetafieldOwnerBase extends Model {}
