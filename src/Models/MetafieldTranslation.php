<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Metafields\Database\Factories\MetafieldTranslationFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\Concerns\GuardsTenantOwnership;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;

/**
 * MetafieldTranslation Model
 *
 * Stores translations for translatable metafield values.
 *
 * @property string $id UUID primary key
 * @property string $tenant_id Canonical value tenant UUID
 * @property string $metafield_id Parent metafield UUID
 * @property string $locale Locale code (en, bg)
 * @property string|null $value Translated value string
 * @property-read Metafield $metafield
 *
 * @api
 *
 * @nvl-consumer-read id
 */
class MetafieldTranslation extends Model implements Ownable
{
    use GuardsTenantOwnership;

    /** @use HasFactory<MetafieldTranslationFactory> */
    use HasFactory;

    use HasOwnership { save as private saveOwned;
        delete as private deleteOwned; }
    use HasUuids;

    /** @param array<string,mixed> $options */
    public function save(array $options = []): bool
    {
        MetafieldOwnership::declared();

        return $this->saveOwned($options);
    }

    /** Reject locale mutation when its native host graph is not configured. */
    public function delete(): ?bool
    {
        MetafieldOwnership::declared();

        return $this->deleteOwned();
    }

    public const string TABLE = MetafieldsTables::I18n;

    protected $table = self::TABLE;

    protected $fillable = [
        'metafield_id',
        'locale',
        'value',
    ];

    /**
     * Return the canonical metafield value for this locale row.
     *
     * @return BelongsTo<Metafield, $this>
     */
    public function metafield(): BelongsTo
    {
        return $this->belongsTo(Metafield::class, 'metafield_id');
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return MetafieldsTables::get(MetafieldsTables::I18n);
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
    protected static function newFactory(): MetafieldTranslationFactory
    {
        return MetafieldTranslationFactory::new();
    }
}
