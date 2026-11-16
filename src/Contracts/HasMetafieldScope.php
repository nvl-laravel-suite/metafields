<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

/**
 * Declares which metafield assignment scopes one owner model supports.
 *
 * @api
 */
interface HasMetafieldScope
{
    public function metafieldScopeKey(): ?string;

    /** @return list<array{value:string,label:string}> */
    public static function metafieldScopeOptions(): array;
}
