<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Illuminate\Validation\Rule;
use Nvl\Data\Traits\DataTransform;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * Owner type, placement, and display options for assigning a metafield definition.
 *
 * @api
 */
#[MapOutputName(CamelCaseMapper::class)]
#[MapInputName(CamelCaseMapper::class)]
final class AssignMetafieldDefinitionPayload extends Data
{
    use DataTransform;

    /**
     * @param  array<string, mixed>|Optional|null  $uiConfig
     */
    public function __construct(
        public readonly string $ownerType,
        public readonly string $section,
        public readonly int $displayOrder = 0,
        public readonly bool $isRequired = false,
        public readonly bool $isActive = true,
        public readonly array|Optional|null $uiConfig = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'ownerType' => ['required', 'string', Rule::in(array_keys((array) config('nvl-metafields.owners', [])))],
            'section' => ['required', 'string', 'max:255'],
            'displayOrder' => ['nullable', 'integer', 'min:0'],
            'isRequired' => ['boolean'],
            'isActive' => ['boolean'],
            'uiConfig' => ['nullable', 'array'],
        ];
    }
}
