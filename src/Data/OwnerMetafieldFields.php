<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The complete admitted field list for one native owner identity.
 *
 * @api
 */
#[TypeScript]
final class OwnerMetafieldFields extends Data
{
    /** @param list<OwnerMetafieldField> $fields */
    public function __construct(public readonly array $fields) {}
}
