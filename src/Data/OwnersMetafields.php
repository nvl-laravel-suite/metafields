<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/**
 * Object maps preserve native morph strings and numeric owner keys.
 *
 * @api
 */
#[TypeScript]
final class OwnersMetafields extends Data
{
    /** @param list<array{type: string, id: string}> $order */
    public function __construct(
        #[LiteralTypeScriptType('Record<string, Record<string, OwnerMetafieldFields>>')]
        public readonly stdClass $owners,
        #[LiteralTypeScriptType('Array<{ type: string; id: string }>')]
        public readonly array $order,
    ) {}

    /** @param OwnerResultMap<OwnerMetafieldFields> $map */
    public static function fromMap(OwnerResultMap $map): self
    {
        return new self($map->jsonSerialize(), $map->order());
    }
}
