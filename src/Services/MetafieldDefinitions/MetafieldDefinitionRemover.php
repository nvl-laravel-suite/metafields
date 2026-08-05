<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;

final class MetafieldDefinitionRemover
{
    public function delete(
        MetafieldDefinition $definition,
        bool $deleteValues = false,
    ): bool {
        if (! $deleteValues && $definition->metafields()->exists()) {
            throw ValidationException::withMessages([
                'deleteValues' => [
                    trans('metafields::metafields/validation.custom.definition.active_values_delete'),
                ],
            ]);
        }

        if ($deleteValues) {
            /** @var list<string> $metafieldIds */
            $metafieldIds = Metafield::query()
                ->where('definition_id', $definition->id)
                ->pluck('id')
                ->all();

            if ($metafieldIds !== []) {
                MetafieldTranslation::query()
                    ->whereIn('metafield_id', $metafieldIds)
                    ->get()
                    ->each
                    ->delete();

                Metafield::query()
                    ->whereIn('id', $metafieldIds)
                    ->get()
                    ->each
                    ->delete();
            }
        }

        $definition->assignments()->get()->each->delete();

        return (bool) $definition->delete();
    }
}
