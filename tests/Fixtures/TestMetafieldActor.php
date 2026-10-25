<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Persisted host actor for explicit-policy regression tests. */
final class TestMetafieldActor extends User
{
    protected $table = 'test_metafield_actors';

    protected $guarded = [];

    public function useKeyColumn(string $key): void
    {
        $this->primaryKey = $key;
    }
}
