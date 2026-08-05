<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

/**
 * MetafieldTranslation Model
 *
 * Stores translations for translatable metafield values.
 *
 * @property string $id UUID primary key
 * @property string $metafield_id Parent metafield UUID
 * @property string $locale Locale code (en, bg)
 * @property string|null $value Translated value string
 * @property-read Metafield $metafield
 */
class MetafieldTranslation extends Model
{
    use HasUuids;

    public const string TABLE = MetafieldsTables::METAFIELDS_I18N;

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
}
