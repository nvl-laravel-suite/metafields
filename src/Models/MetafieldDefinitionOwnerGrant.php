<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Recipient-owned permission to import one central definition revision.
 * @property string $id
 * @property string $definition_id
 * @property int $source_revision
 * @property int $revision
 * @property bool $enabled
 * @property Carbon|null $revoked_at
 * @property string $owner_scope_key
 * @property string $owner_reference
 */
final class MetafieldDefinitionOwnerGrant extends Model implements Ownable
{
    use HasOwnership;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }

    /** @return BelongsTo<MetafieldDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetafieldModelRegistry::modelClass(), 'definition_id')->withTrashed();
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

    public function getTable(): string
    {
        return MetafieldsTables::get(MetafieldsTables::OwnerGrants);
    }

    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('metafields') ?? parent::getConnectionName());
    }
}
