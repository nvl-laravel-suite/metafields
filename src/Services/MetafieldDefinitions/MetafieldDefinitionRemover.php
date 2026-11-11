<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services\MetafieldDefinitions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;

/**
 * @internal
 */
final class MetafieldDefinitionRemover
{
    public function delete(
        MetafieldDefinition $definition,
        bool $deleteValues = false,
    ): bool {
        if (! $deleteValues && $definition->metafields()->exists()) {
            throw ValidationException::withMessages([
                'deleteValues' => [
                    trans('nvl-metafields::metafields/validation.custom.definition.active_values_delete'),
                ],
            ]);
        }

        if ($deleteValues) {
            // A hundred values at a time, so no statement binds an unbounded identifier list.
            Metafield::query()->where('definition_id', $definition->id)->chunkById(100, static function (Collection $values): void {
                MetafieldTranslation::query()->whereIn('metafield_id', $values->modelKeys())->get()->each->delete();
                $values->each->delete();
            });
        }

        $definition->assignments()->get()->each->delete();

        return (bool) $definition->delete();
    }
}
