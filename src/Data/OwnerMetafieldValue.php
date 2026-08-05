<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Support\MetafieldValueSerializer;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Canonical serialized value returned after an owner metafield mutation. */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
#[TypeScript]
final class OwnerMetafieldValue extends Data
{
    /**
     * @param  array<string, mixed>|null  $translations
     */
    public function __construct(
        #[LiteralTypeScriptType('string')]
        public readonly string $id,
        #[LiteralTypeScriptType('string')]
        public readonly string $definitionId,
        #[LiteralTypeScriptType('string')]
        public readonly string $ownerId,
        #[LiteralTypeScriptType('string')]
        public readonly string $ownerType,
        public readonly MetafieldTypeEnum $type,
        #[LiteralTypeScriptType('boolean')]
        public readonly bool $isTranslatable,
        #[LiteralTypeScriptType('unknown | null')]
        public readonly mixed $value,
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public readonly ?array $translations,
        #[LiteralTypeScriptType('string | null')]
        public readonly ?string $referencedId,
        #[LiteralTypeScriptType('number')]
        public readonly int $revision,
    ) {}

    /**
     * Build a consumer-safe value payload from a persisted metafield.
     */
    public static function fromModel(
        Metafield $metafield,
        string $ownerType,
        ?string $locale = null,
    ): self {
        $metafield->loadMissing(['definition', 'translations']);
        $definition = $metafield->definition;

        /** @var array<string, mixed>|null $translations */
        $translations = $definition->is_translatable
            ? $metafield->translations
                ->filter(
                    static fn (mixed $translation): bool => $translation instanceof MetafieldTranslation,
                )
                ->mapWithKeys(
                    static fn (MetafieldTranslation $translation): array => [
                        $translation->locale => MetafieldValueSerializer::serialize(
                            $definition->type,
                            $definition->type->cast($translation->value),
                        ),
                    ],
                )
                ->all()
            : null;

        if ($translations === []) {
            $translations = null;
        }

        return new self(
            id: $metafield->id,
            definitionId: $metafield->definition_id,
            ownerId: $metafield->metafieldable_id,
            ownerType: $ownerType,
            type: $definition->type,
            isTranslatable: $definition->is_translatable,
            value: MetafieldValueSerializer::serialize(
                $definition->type,
                match ($definition->type) {
                    MetafieldTypeEnum::Reference => $metafield->referenced_id,
                    MetafieldTypeEnum::ReferenceList => $definition->type->cast($metafield->value),
                    default => $metafield->getValue($locale),
                },
            ),
            translations: $translations,
            referencedId: $metafield->referenced_id,
            revision: $metafield->revision,
        );
    }
}
