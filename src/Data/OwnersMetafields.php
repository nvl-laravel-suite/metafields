<?php

declare(strict_types=1);

namespace Nvl\Metafields\Data;

use Nvl\Support\Owners\OwnerResultMap;
use Spatie\LaravelData\Data;
use stdClass;

/**
 * Object maps preserve native morph strings and numeric owner keys.
 *
 * @api
 */
final class OwnersMetafields extends Data
{
    /** @param list<array{type: string, id: string}> $order */
    public function __construct(
        public readonly stdClass $owners,
        public readonly array $order,
    ) {}

    /** @param OwnerResultMap<OwnerMetafieldFields> $map */
    public static function fromMap(OwnerResultMap $map): self
    {
        return new self($map->jsonSerialize(), $map->order());
    }
}
