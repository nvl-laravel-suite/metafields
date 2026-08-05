<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

/**
 * Stores localized definition copy and defaults for one metafield locale.
 *
 * @property string $id
 * @property string $metafield_definition_id
 * @property string $locale
 * @property string $title
 * @property string|null $description
 * @property string|null $hint
 * @property string|null $default_value
 * @property array<string, mixed>|null $properties
 * @property-read MetafieldDefinition $definition
 */
final class MetafieldDefinitionTranslation extends Model
{
    use HasUuids;

    public const string TABLE = MetafieldsTables::METAFIELDS_DEFINITIONS_I18N;

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
}
