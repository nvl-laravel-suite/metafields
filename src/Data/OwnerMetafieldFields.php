<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Spatie\LaravelData\Data;

/**
 * The complete admitted field list for one native owner identity.
 *
 * @api
 */
final class OwnerMetafieldFields extends Data
{
    /** @param list<OwnerMetafieldField> $fields */
    public function __construct(public readonly array $fields) {}
}
