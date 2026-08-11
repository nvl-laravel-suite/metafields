<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Nvl\Metafields\Database\Factories\MetafieldDefinitionFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * MetafieldDefinition Model
 *
 * Defines the structure and type of metafields available in the system.
 *
 * @property string $id UUID primary key
 * @property string $namespace Namespace for grouping
 * @property string $key Specific key identifier
 * @property string $handle Unique namespace.key combination
 * @property MetafieldTypeEnum $type Data type (string, integer, etc.)
 * @property string|null $referenced_model_type Model type for reference fields
 * @property bool $is_translatable Whether translations are supported
 * @property bool $is_required Whether the field is mandatory
 * @property bool $is_filterable Whether the field can be filtered
 * @property list<string>|null $validation_rules Additional validation rules
 * @property array<int, array{key:string, type:string, isRequired:bool}>|null $json_property_schema Typed property schema for JSON metafields
 * @property string|null $default_value Serialized default value for non-reference metafields
 * @property string|null $default_referenced_id Default referenced UUID for reference metafields
 * @property int $display_order Order in UI
 * @property int $revision Optimistic concurrency revision
 * @property string|null $active_handle Unique handle for an active definition
 * @property Carbon|null $archived_at Archive timestamp
 * @property Carbon|null $deleted_at Soft-delete timestamp
 */
class MetafieldDefinition extends Model implements TranslatableModel
{
    /** @use HasFactory<MetafieldDefinitionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;
    use Translatable;

    public const string TABLE = MetafieldsTables::Definitions;

    protected $table = self::TABLE;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
    ];

    protected static function newFactory(): MetafieldDefinitionFactory
    {
        return MetafieldDefinitionFactory::new();
    }

    protected $fillable = [
        'namespace',
        'key',
        'handle',
        'type',
        'referenced_model_type',
        'is_translatable',
        'is_required',
        'is_filterable',
        'validation_rules',
        'json_property_schema',
        'default_value',
        'default_referenced_id',
        'display_order',
        'revision',
        'archived_at',
        'active_handle',
    ];

    /**
     * Configure localized definition copy and defaults.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: MetafieldDefinitionTranslation::class,
            foreignKey: 'metafield_definition_id',
            fields: [
                'title',
                'description',
                'hint',
                'default_value',
                'properties',
            ],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
        );
    }

    /**
     * Get the model's casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MetafieldTypeEnum::class,
            'is_translatable' => 'boolean',
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'validation_rules' => 'array',
            'json_property_schema' => 'array',
            'default_referenced_id' => 'string',
            'display_order' => 'integer',
            'revision' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to a specific namespace.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInNamespace(Builder $query, string $namespace): void
    {
        $query->where('namespace', $namespace);
    }

    /**
     * Scope a query to definitions available for runtime use.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNotNull('active_handle');
    }

    /**
     * Get the metafields associated with this definition.
     *
     * @return HasMany<Metafield, $this>
     */
    public function metafields(): HasMany
    {
        return $this->hasMany(Metafield::class, 'definition_id');
    }

    /**
     * @return HasMany<MetafieldDefinitionAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(MetafieldDefinitionAssignment::class, 'definition_id')
            ->orderBy('display_order')
            ->orderBy('owner_type');
    }

    public function hasDefaultValue(?string $locale = null): bool
    {
        if ($this->is_translatable) {
            return $this->translated('default_value', $locale) !== null;
        }

        return match ($this->type) {
            MetafieldTypeEnum::Reference => is_string($this->default_referenced_id)
                && $this->default_referenced_id !== '',
            default => $this->default_value !== null,
        };
    }

    public function getDefaultValue(?string $locale = null): mixed
    {
        if (! $this->hasDefaultValue($locale)) {
            return null;
        }

        if ($this->type === MetafieldTypeEnum::Reference) {
            return $this->resolveDefaultReference();
        }

        $translatedDefault = $this->is_translatable
            ? $this->translated('default_value', $locale)
            : null;

        return $this->type->cast($this->is_translatable ? $translatedDefault : $this->default_value);
    }

    public function getSerializableDefaultValue(?string $locale = null): mixed
    {
        if (! $this->hasDefaultValue($locale)) {
            return null;
        }

        if ($this->type === MetafieldTypeEnum::Reference) {
            return $this->default_referenced_id;
        }

        $defaultValue = $this->getDefaultValue($locale);

        if ($defaultValue instanceof Carbon) {
            return $defaultValue->format('Y-m-d H:i:s');
        }

        return $defaultValue;
    }

    /**
     * Return the localized definition title.
     */
    public function displayTitle(?string $locale = null): string
    {
        $title = $this->translated('title', $locale);

        return is_string($title) && $title !== '' ? $title : $this->handle;
    }

    /**
     * Return the localized definition description.
     */
    public function displayDescription(?string $locale = null): ?string
    {
        $description = $this->translated('description', $locale);

        return is_string($description) ? $description : null;
    }

    /**
     * Return the localized definition hint.
     */
    public function displayHint(?string $locale = null): ?string
    {
        $hint = $this->translated('hint', $locale);

        return is_string($hint) ? $hint : null;
    }

    public function setDefaultValue(mixed $value): void
    {
        if ($value === null || $value === '') {
            $this->clearDefaultValue();

            return;
        }

        if ($this->type === MetafieldTypeEnum::Reference) {
            $identifier = $value instanceof Model ? $value->getKey() : $value;
            $this->default_referenced_id = is_string($identifier) || is_int($identifier)
                ? (string) $identifier
                : null;
            $this->default_value = null;

            return;
        }

        $storedValue = $this->type->storeCast($value);

        $this->default_value = is_scalar($storedValue) || $storedValue === null
            ? (string) $storedValue
            : json_encode($storedValue, JSON_THROW_ON_ERROR);
        $this->default_referenced_id = null;
    }

    public function clearDefaultValue(): void
    {
        $this->default_value = null;
        $this->default_referenced_id = null;
    }

    /**
     * Generate handle from namespace and key.
     */
    public static function generateHandle(string $namespace, string $key): string
    {
        return "{$namespace}.{$key}";
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (MetafieldDefinition $definition) {
            $definition->handle = static::generateHandle($definition->namespace, $definition->key);
            $definition->active_handle = $definition->archived_at === null
                && $definition->deleted_at === null
                ? $definition->handle
                : null;
        });
        static::updating(function (MetafieldDefinition $definition): void {
            if (! $definition->isDirty('revision')) {
                $revision = $definition->getOriginal('revision');
                $definition->revision = (is_int($revision) ? $revision : 0) + 1;
            }
        });
        static::deleting(function (MetafieldDefinition $definition): void {
            $definition->active_handle = null;
            $definition->saveQuietly();
        });
        static::restoring(function (MetafieldDefinition $definition): void {
            $definition->active_handle = $definition->handle;
        });
    }

    protected function resolveDefaultReference(): ?Model
    {
        if (! $this->default_referenced_id || ! $this->referenced_model_type) {
            return null;
        }

        return MetafieldReferenceModelRegistry::findReferencedRecord(
            $this->referenced_model_type,
            $this->default_referenced_id,
        );
    }
}
