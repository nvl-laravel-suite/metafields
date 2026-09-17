<?php

declare(strict_types=1);

namespace Nvl\Metafields\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Nvl\Metafields\Database\Factories\MetafieldFactory;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Concerns\GuardsTenantOwnership;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Tenancy\Exceptions\TenantBoundaryViolation;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/**
 * Metafield Model
 *
 * Stores the actual value of a metafield for a specific entity.
 * Supports translatable and non-translatable values, including references.
 *
 * @property string $id UUID primary key
 * @property string $tenant_id Canonical tenant UUID inherited from the owner
 * @property string $definition_id Linked definition UUID
 * @property string $metafieldable_id Polymorphic owner ID
 * @property string $metafieldable_type Polymorphic owner type
 * @property string|null $referenced_id UUID of the referenced record
 * @property string|null $value Non-translatable base value
 * @property int $revision Optimistic concurrency revision
 * @property-read MetafieldDefinition $definition
 * @property-read Model $metafieldable
 */
class Metafield extends Model implements TranslatableModel
{
    use GuardsTenantOwnership;
    /** @use HasFactory<MetafieldFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;
    use Translatable;

    public const string TABLE = MetafieldsTables::Metafields;

    protected $table = self::TABLE;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 1,
    ];

    protected static function newFactory(): MetafieldFactory
    {
        return MetafieldFactory::new();
    }

    protected $fillable = [
        'definition_id',
        'metafieldable_id',
        'metafieldable_type',
        'referenced_id',
        'value',
        'revision',
    ];

    /**
     * Configure translation options.
     */
    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(
            translationModel: MetafieldTranslation::class,
            foreignKey: 'metafield_id',
            fields: ['value'],
            mutationPolicy: TranslationMutationPolicy::DomainActionOnly,
            ownershipResource: 'metafields.values',
        );
    }

    /**
     * Return package-owned casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Metafield $metafield): void {
            if (! $metafield->isDirty('revision')) {
                $revision = $metafield->getOriginal('revision');
                $metafield->revision = (is_int($revision) ? $revision : 0) + 1;
            }
        });
    }

    /**
     * Get the definition relationship.
     *
     * @return BelongsTo<MetafieldDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetafieldDefinition::class, 'definition_id');
    }

    /**
     * Get the polymorphic owner relationship.
     *
     * @return MorphTo<Model, $this>
     */
    public function metafieldable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the resolved value of the metafield.
     */
    public function getValue(?string $locale = null): mixed
    {
        $this->assertLoadedGraphOwnership();
        if ($this->definition->type === MetafieldTypeEnum::Reference) {
            return $this->resolveReference();
        }

        if ($this->definition->type === MetafieldTypeEnum::ReferenceList) {
            return collect((array) $this->definition->type->cast($this->value))
                ->map(
                    function (mixed $identifier): ?Model {
                        $reference = MetafieldReferenceModelRegistry::findReferencedRecord(
                        $this->definition->referenced_model_type,
                        $identifier,
                        );
                        $this->assertReferenceOwnership($reference);

                        return $reference;
                    },
                )
                ->filter()
                ->values();
        }

        if ($this->definition->is_translatable) {
            $val = $this->translated('value', $locale);

            return $this->definition->type->cast($val);
        }

        return $this->definition->type->cast($this->value);
    }

    /**
     * Resolve the reference model if type is REFERENCE.
     */
    protected function resolveReference(): ?Model
    {
        if (! $this->referenced_id || ! $this->definition->referenced_model_type) {
            return null;
        }

        $reference = MetafieldReferenceModelRegistry::findReferencedRecord(
            $this->definition->referenced_model_type,
            $this->referenced_id,
        );
        $this->assertReferenceOwnership($reference);

        return $reference;
    }

    /** Reject mismatched loaded parent and locale rows before exposing values. */
    private function assertLoadedGraphOwnership(): void
    {
        app(TenantBoundary::class)->assertRecord($this, 'metafields.values');
        $tenant = $this->getAttribute('tenant_id');
        app(TenantBoundary::class)->assertRecord($this->definition, 'metafields.definitions');
        if (is_string($tenant) && $this->definition->getAttribute('tenant_id') !== $tenant) {
            throw new TenantBoundaryViolation('A Metafield definition belongs to another tenant.');
        }
        if ($this->relationLoaded('translations')) {
            foreach ($this->translations as $translation) {
                if (is_string($tenant) && $translation->getAttribute('tenant_id') !== $tenant) {
                    throw new TenantBoundaryViolation('A Metafield translation belongs to another tenant.');
                }
            }
        }
    }

    /** Reject platform and foreign-tenant reference targets. */
    private function assertReferenceOwnership(?Model $reference): void
    {
        if (! $reference instanceof Model) {
            return;
        }
        $tenant = $this->getAttribute('tenant_id');
        if (is_string($tenant) && $reference->getAttribute('tenant_id') !== $tenant) {
            throw new TenantBoundaryViolation('A Metafield reference belongs to another tenant.');
        }
    }
}
