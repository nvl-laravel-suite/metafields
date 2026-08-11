<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Metafields\Database\Factories\MetafieldDefinitionAssignmentFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;

/**
 * @property string $id
 * @property string $definition_id
 * @property string $owner_type
 * @property string|null $section
 * @property int $display_order
 * @property bool $is_required
 * @property bool $is_active
 * @property array<string, mixed>|null $ui_config
 * @property-read MetafieldDefinition|null $definition
 */
class MetafieldDefinitionAssignment extends Model
{
    /** @use HasFactory<MetafieldDefinitionAssignmentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const string TABLE = MetafieldsTables::DefinitionAssignments;

    protected $table = self::TABLE;

    protected static function newFactory(): MetafieldDefinitionAssignmentFactory
    {
        return MetafieldDefinitionAssignmentFactory::new();
    }

    protected $fillable = [
        'definition_id',
        'owner_type',
        'section',
        'display_order',
        'is_required',
        'is_active',
        'ui_config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'ui_config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MetafieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetafieldDefinition::class, 'definition_id');
    }
}
