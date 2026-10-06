<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Metafields\Traits\HasMetafields;

/**
 * Distinct string-key owner with a retained host visibility scope.
 *
 * @property string $id
 * @property string $name
 */
final class BatchStringMetafieldOwner extends Model
{
    use HasMetafields;
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'test_batch_string_metafield_owners';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::addGlobalScope('host-visibility', static fn (Builder $query) => $query->where('name', '!=', 'hidden'));
    }
}
