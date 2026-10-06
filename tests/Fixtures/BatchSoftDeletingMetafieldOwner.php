<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Metafields\Traits\HasMetafields;

/**
 * Canonical tenant fixture with a native custom soft-delete column.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property string $name
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class BatchSoftDeletingMetafieldOwner extends Model
{
    use HasMetafields;
    use SoftDeletes;

    public const string DELETED_AT = 'archived_at';

    protected $table = 'test_live_metafield_owners';

    protected $guarded = [];
}
