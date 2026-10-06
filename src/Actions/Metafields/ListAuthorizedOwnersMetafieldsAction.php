<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Data\OwnersMetafields;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader;

/**
 * Lists bounded authorized fields without per-owner action calls.
 *
 * @api
 */
final readonly class ListAuthorizedOwnersMetafieldsAction implements ListAuthorizedOwnersMetafieldsContract
{
    /** Retain the package-owned batch reader. */
    public function __construct(private OwnerMetafieldBatchReader $reader) {}

    /** @param list<Model> $owners */
    public function execute(array $owners, ?string $locale = null): OwnersMetafields
    {
        return $this->reader->read($owners, $locale);
    }
}
