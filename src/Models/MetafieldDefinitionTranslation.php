<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Metafields\Database\Factories\MetafieldDefinitionTranslationFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\Concerns\GuardsTenantOwnership;
use Nvl\Support\Config\PackageStorage;

/**
 * Stores localized definition copy and defaults for one metafield locale.
 *
 * @property string $id
 * @property string|null $tenant_id Canonical definition tenant UUID
 * @property string|null $ownership_key Canonical definition partition identity
 * @property string $metafield_definition_id
 * @property string $locale
 * @property string $title
 * @property string|null $description
 * @property string|null $hint
 * @property string|null $default_value
 * @property array<string, mixed>|null $properties
 * @property-read MetafieldDefinition $definition
 *
 * @api
 *
 * @nvl-consumer-read id
 */
final class MetafieldDefinitionTranslation extends Model
{
    use GuardsTenantOwnership;

    /** @use HasFactory<MetafieldDefinitionTranslationFactory> */
    use HasFactory;

    use HasUuids;

    public const string TABLE = MetafieldsTables::DefinitionsI18n;

    protected $table = self::TABLE;

    protected $fillable = [
        'metafield_definition_id',
        'locale',
        'title',
        'description',
        'hint',
        'default_value',
        'properties',
    ];

    /**
     * Return translation attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    /**
     * Return the canonical metafield definition for this locale row.
     *
     * @return BelongsTo<MetafieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetafieldDefinition::class, 'metafield_definition_id');
    }

    /** Resolve the configured package storage table. */
    public function getTable(): string
    {
        return MetafieldsTables::get(MetafieldsTables::DefinitionsI18n);
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
    protected static function newFactory(): MetafieldDefinitionTranslationFactory
    {
        return MetafieldDefinitionTranslationFactory::new();
    }
}
