<?php

declare(strict_types=1);

namespace Nvl\Metafields\Contracts;

/**
 * Supplies a persisted owner column whose exact value represents the PHP scope key.
 *
 * @api
 */
interface HasSqlMetafieldScope extends HasMetafieldScope
{
    public static function metafieldScopeColumn(): string;
}
