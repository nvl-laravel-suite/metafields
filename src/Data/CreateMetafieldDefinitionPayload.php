<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Translatable\Rules\SupportedLocaleMapRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;

/**
 * Validated contract for creating a metafield definition and its initial localized copy.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class CreateMetafieldDefinitionPayload extends MetafieldDefinitionMutationPayload
{
    /**
     * Return creation-specific validation rules.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            ...parent::rules(),
            'expectedRevision' => ['prohibited'],
            'translations' => ['required', 'array', 'min:1', new SupportedLocaleMapRule],
            'translations.*.title' => ['required', 'string', 'max:255'],
        ];
    }
}
