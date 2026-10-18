<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Nvl\Metafields\Database\Factories\MetafieldDefinitionTenantGrantFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\Concerns\GuardsTenantOwnership;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Tenancy\LegacyMetafieldGrantPersistence;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

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
 *
 * @api
 */
class MetafieldDefinitionTenantGrant extends Model implements Ownable
{
    use GuardsTenantOwnership;

    /** @use HasFactory<MetafieldDefinitionTenantGrantFactory> */
    use HasFactory;

    use HasOwnership { save as private saveOwned; }
    use HasUuids;

    /** Preserve the bounded V1 platform grant workflow and enforce every adopted write.
     * @param  array<string,mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return Container::getInstance()->make(LegacyMetafieldGrantPersistence::class)->save($this, fn (): bool => parent::save($options), fn (): bool => $this->saveOwned($options));
    }

    public const string TABLE = MetafieldsTables::TenantGrants;

    protected $table = self::TABLE;

    /** @var list<string> */
    protected $fillable = [];

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

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return MetafieldsTables::get(MetafieldsTables::TenantGrants);
    }

    /** Resolve the package connection through shared infrastructure defaults. */
    public function getConnectionName(): ?string
    {
        return PackageStorage::connectionName($this->connection ?? PackageStorage::connection('metafields') ?? parent::getConnectionName());
    }

    /**
     * Return the package's runtime fixture factory.
     *
     * @internal
     */
    protected static function newFactory(): MetafieldDefinitionTenantGrantFactory
    {
        return MetafieldDefinitionTenantGrantFactory::new();
    }
}
