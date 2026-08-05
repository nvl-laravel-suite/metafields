<?php

declare(strict_types=1);

namespace Nvl\Metafields\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Normalizes Eloquent model keys for string-compatible polymorphic columns.
 */
final class ModelIdentifier
{
    public static function required(Model $model): string
    {
        $identifier = $model->getKey();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new InvalidArgumentException(
                'The model ['.$model::class.'] must have a string- or integer-compatible key.',
            );
        }

        $normalized = trim((string) $identifier);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'The model ['.$model::class.'] must be persisted before metafields can be assigned.',
            );
        }

        return $normalized;
    }

    private function __construct() {}
}
