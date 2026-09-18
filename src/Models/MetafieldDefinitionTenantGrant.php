<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\Concerns\GuardsTenantOwnership;

/**
 * Records revocable permission for one tenant to copy one platform definition.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $definition_id
 * @property int $source_revision
 * @property int $revision
 * @property bool $enabled
 * @property Carbon|null $revoked_at
 * @property-read MetafieldDefinition $definition
 */
final class MetafieldDefinitionTenantGrant extends Model
{
    use GuardsTenantOwnership;
    use HasUuids;

    public const string TABLE = MetafieldsTables::TenantGrants;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<MetafieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetafieldDefinition::class, 'definition_id')->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_revision' => 'integer',
            'revision' => 'integer',
            'enabled' => 'boolean',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
