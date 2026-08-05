<?php

declare(strict_types=1);

namespace Nvl\Metafields\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * MetafieldIntegrityException
 *
 * Raised when persisted metafield rows violate runtime invariants.
 */
final class MetafieldIntegrityException extends MetafieldException
{
    /**
     * @param  list<string>  $definitionIds
     */
    public static function duplicateActiveOwnerDefinitionRecords(
        Model $owner,
        array $definitionIds,
    ): self {
        $definitions = implode(', ', $definitionIds);
        $ownerClass = $owner::class;
        $identifier = $owner->getKey();
        $ownerKey = is_string($identifier) || is_int($identifier)
            ? (string) $identifier
            : 'unknown';

        return new self(
            "Duplicate active metafield rows detected for owner [{$ownerClass}:{$ownerKey}] and definitions [{$definitions}].",
        );
    }
}
