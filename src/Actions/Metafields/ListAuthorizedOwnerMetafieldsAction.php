<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Enums\MetafieldAbility;

/**
 * Lists one owner's Metafield projection through the authorization boundary.
 *
 * Delegation to ListOwnerMetafieldsAction is deliberate orchestration so the
 * canonical bounded projection remains shared after authorization.
 */
final readonly class ListAuthorizedOwnerMetafieldsAction
{
    /**
     * Create the authorized owner-field reader.
     */
    public function __construct(
        private MetafieldAuthorization $authorization,
        private ListOwnerMetafieldsAction $fields,
    ) {}

    /**
     * Return locale-resolved owner fields after authorizing storage access.
     *
     * @return Collection<int, OwnerMetafieldField>
     */
    public function execute(Model $owner, ?string $locale = null): Collection
    {
        $this->authorization->authorizeOwner(MetafieldAbility::ViewOwner, $owner);

        return $this->fields->execute($owner, $locale);
    }
}
