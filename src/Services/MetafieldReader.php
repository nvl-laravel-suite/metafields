<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldBatchReader;
use Nvl\Metafields\Services\Metafields\OwnerMetafieldFieldCatalog;
use Nvl\Support\Enums\SystemActor;
use stdClass;

/**
 * Scoped native definition and owner value reads.
 *
 * @api
 */
final readonly class MetafieldReader
{
    public function __construct(
        private MetafieldActorPolicy $actors,
        private MetafieldDefinitionCatalog $definitions,
        private MetafieldAuthorization $authorization,
        private MetafieldOwnerModelResolver $owners,
        private OwnerMetafieldFieldCatalog $fields,
        private OwnerMetafieldBatchReader $batches,
    ) {}

    /** @return Collection<int, MetafieldDefinition> */
    public function definitions(Authenticatable|SystemActor $actor): Collection
    {
        $actor = $this->actors->authorize($actor, 'read');
        $this->authorization->authorizeDefinition($actor, MetafieldAbility::ListDefinitions);

        return $this->definitions->list();
    }

    /** @return Collection<int, array{definition: MetafieldDefinition, assignment: MetafieldDefinitionAssignment, value: ?Metafield}> */
    public function forOwner(Model $owner, Authenticatable|SystemActor $actor, ?string $locale = null): Collection
    {
        $actor = $this->actors->authorize($actor, 'read');
        $this->authorization->authorizeOwner($actor, MetafieldAbility::ViewOwner, $owner);
        $canonical = $this->owners->canonical($owner);
        if ($canonical->getAttributes() !== $owner->getAttributes() || $owner->getRelations() !== []) {
            $this->authorization->authorizeOwner($actor, MetafieldAbility::ViewOwner, $canonical);
        }

        return $this->fields->list($canonical, $locale);
    }

    /** @param list<Model> $owners
     * @return array{owners: stdClass, order: list<array{type: string, id: string}>}
     */
    public function forOwners(array $owners, Authenticatable|SystemActor $actor, ?string $locale = null): array
    {
        $actor = $this->actors->authorize($actor, 'read');

        return $this->batches->read($owners, $actor, $locale);
    }
}
