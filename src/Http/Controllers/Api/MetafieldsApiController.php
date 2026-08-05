<?php

declare(strict_types=1);

namespace Nvl\Metafields\Http\Controllers\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Nvl\Metafields\Actions\MetafieldDefinitions\ArchiveMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\ListMetafieldDefinitionsAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\UpdateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\DeleteOwnerMetafieldAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Data\ArchiveMetafieldDefinitionPayload;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Data\DeleteMetafieldDefinitionPayload;
use Nvl\Metafields\Data\DeleteOwnerMetafieldPayload;
use Nvl\Metafields\Data\MetafieldDefinitionAssignmentPayload;
use Nvl\Metafields\Data\MetafieldDefinitionPayload;
use Nvl\Metafields\Data\OwnerMetafieldField;
use Nvl\Metafields\Data\OwnerMetafieldValue;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Data\UpdateMetafieldDefinitionPayload;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Services\Metafields\MetafieldOwnerModelResolver;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Exposes canonical Metafield definition, owner query, and owner mutation endpoints.
 */
final class MetafieldsApiController extends Controller
{
    /**
     * Create the canonical Metafields API controller.
     *
     * @param  MetafieldOwnerRegistry  $ownerRegistry  Configured owner registry
     * @param  MetafieldOwnerModelResolver  $ownerResolver  Configured owner model resolver
     */
    public function __construct(
        private readonly MetafieldOwnerRegistry $ownerRegistry,
        private readonly MetafieldOwnerModelResolver $ownerResolver,
        private readonly MetafieldAuthorization $authorization,
    ) {}

    /**
     * Return every Metafield definition with assignment metadata.
     *
     * @param  ListMetafieldDefinitionsAction  $action  Definition listing action
     * @return JsonResponse Metafield definition collection
     */
    public function definitions(ListMetafieldDefinitionsAction $action): JsonResponse
    {
        $this->authorization->authorizeDefinition(MetafieldAbility::ListDefinitions);
        $definitions = $action->execute()
            ->map(fn (MetafieldDefinition $definition): array => $this->definitionPayload($definition))
            ->values()
            ->all();

        return response()->json(['data' => $definitions], 200);
    }

    /**
     * Create a Metafield definition and its assignments.
     *
     * @param  CreateMetafieldDefinitionPayload  $data  Validated definition data
     * @param  CreateMetafieldDefinitionAction  $action  Definition creation action
     * @return JsonResponse Created definition payload
     */
    public function storeDefinition(
        CreateMetafieldDefinitionPayload $data,
        CreateMetafieldDefinitionAction $action,
    ): JsonResponse {
        $this->authorization->authorizeDefinition(MetafieldAbility::CreateDefinition);

        return response()->json(
            ['data' => $this->definitionPayload($action->execute($data))],
            HttpResponse::HTTP_CREATED,
        );
    }

    /**
     * Return one Metafield definition with assignment metadata.
     *
     * @param  MetafieldDefinition  $definition  Route-bound definition
     * @return JsonResponse Metafield definition payload
     */
    public function showDefinition(MetafieldDefinition $definition): JsonResponse
    {
        $this->authorization->authorizeDefinition(MetafieldAbility::ViewDefinition, $definition);

        return response()->json(['data' => $this->definitionPayload($definition)]);
    }

    /**
     * Update a Metafield definition and its assignments.
     *
     * @param  MetafieldDefinition  $definition  Route-bound definition
     * @param  UpdateMetafieldDefinitionPayload  $data  Validated definition data
     * @param  UpdateMetafieldDefinitionAction  $action  Definition update action
     * @return JsonResponse Updated definition payload
     */
    public function updateDefinition(
        MetafieldDefinition $definition,
        UpdateMetafieldDefinitionPayload $data,
        UpdateMetafieldDefinitionAction $action,
    ): JsonResponse {
        $this->authorization->authorizeDefinition(MetafieldAbility::UpdateDefinition, $definition);

        return response()->json(['data' => $this->definitionPayload($action->execute($definition, $data))], 200);
    }

    /**
     * Delete a Metafield definition with the requested value strategy.
     *
     * @param  MetafieldDefinition  $definition  Route-bound definition
     * @param  DeleteMetafieldDefinitionPayload  $data  Validated deletion options
     * @param  DeleteMetafieldDefinitionAction  $action  Definition deletion action
     * @return JsonResponse Deletion result payload
     */
    public function destroyDefinition(
        MetafieldDefinition $definition,
        DeleteMetafieldDefinitionPayload $data,
        DeleteMetafieldDefinitionAction $action,
    ): JsonResponse {
        $this->authorization->authorizeDefinition(MetafieldAbility::DeleteDefinition, $definition);

        return response()->json([
            'data' => [
                'deleted' => $action->execute(
                    $definition,
                    $data->expectedRevision,
                    $data->deleteValues,
                ),
            ],
        ], 200);
    }

    /**
     * Archive or restore one definition using optimistic concurrency.
     */
    public function archiveDefinition(
        MetafieldDefinition $definition,
        ArchiveMetafieldDefinitionPayload $data,
        ArchiveMetafieldDefinitionAction $action,
    ): JsonResponse {
        $this->authorization->authorizeDefinition(MetafieldAbility::UpdateDefinition, $definition);

        return response()->json([
            'data' => $this->definitionPayload($action->execute($definition, $data)),
        ]);
    }

    /**
     * Return every configured runtime Metafield owner type.
     *
     * @return JsonResponse Metafield owner registry payload
     */
    public function owners(): JsonResponse
    {
        $this->authorization->authorizeOwner(MetafieldAbility::ListOwners);

        return response()->json([
            'data' => collect(array_keys($this->ownerRegistry->all()))
                ->filter(fn (string $type): bool => $this->ownerRegistry->supportsRuntimeEditing($type))
                ->map(fn (string $type): array => $this->ownerRegistry->forType($type)->toArray())
                ->values()
                ->all(),
        ], 200);
    }

    /**
     * Return editable Metafield fields for one configured owner.
     *
     * @param  string  $ownerType  Configured owner type value
     * @param  string  $ownerId  Configured owner identifier
     * @param  ListOwnerMetafieldsAction  $action  Owner field listing action
     * @return JsonResponse Owner identity and editable field payload
     */
    public function ownerFields(
        string $ownerType,
        string $ownerId,
        ListOwnerMetafieldsAction $action,
    ): JsonResponse {
        $owner = $this->resolveOwner($ownerType, $ownerId);
        $this->authorization->authorizeOwner(MetafieldAbility::ViewOwner, $owner);

        return response()->json([
            'data' => [
                'owner' => $this->ownerPayload($owner),
                'fields' => $action->execute($owner)
                    ->map(static fn (OwnerMetafieldField $field): array => $field->toArray())
                    ->values()
                    ->all(),
            ],
        ], 200);
    }

    /**
     * Synchronize Metafield values for one configured owner.
     *
     * @param  string  $ownerType  Configured owner type value
     * @param  string  $ownerId  Configured owner identifier
     * @param  SyncOwnerMetafieldsPayload  $data  Validated owner values
     * @param  SyncOwnerMetafieldsAction  $action  Owner sync action
     * @return JsonResponse Synchronized owner Metafield payload
     */
    public function syncOwnerFields(
        string $ownerType,
        string $ownerId,
        SyncOwnerMetafieldsPayload $data,
        SyncOwnerMetafieldsAction $action,
    ): JsonResponse {
        $owner = $this->resolveOwner($ownerType, $ownerId);

        return $this->syncOwner($owner, $data, $action);
    }

    /**
     * Delete one Metafield value from a configured owner.
     *
     * @param  string  $ownerType  Configured owner type value
     * @param  string  $ownerId  Configured owner identifier
     * @param  MetafieldDefinition  $definition  Route-bound definition
     * @param  DeleteOwnerMetafieldPayload  $data  Revision-aware deletion payload
     * @param  DeleteOwnerMetafieldAction  $action  Owner value deletion action
     * @return JsonResponse Deletion result payload
     */
    public function destroyOwnerField(
        string $ownerType,
        string $ownerId,
        MetafieldDefinition $definition,
        DeleteOwnerMetafieldPayload $data,
        DeleteOwnerMetafieldAction $action,
    ): JsonResponse {
        $owner = $this->resolveOwner($ownerType, $ownerId);

        return $this->deleteOwnerMetafield($owner, $definition, $data, $action);
    }

    /**
     * Resolve one configured owner model or emit an HTTP not-found response.
     *
     * @param  string  $ownerType  Configured owner type value
     * @param  string  $ownerId  Configured owner identifier
     * @return Model Resolved owner model
     */
    private function resolveOwner(string $ownerType, string $ownerId): Model
    {
        try {
            return $this->ownerResolver->resolve($ownerType, $ownerId);
        } catch (InvalidArgumentException) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }
    }

    /**
     * Sync owner metafields and return the canonical API payload.
     *
     * @param  Model  $owner  Owner model receiving metafield values
     * @param  SyncOwnerMetafieldsPayload  $data  Validated owner metafield payload
     * @param  SyncOwnerMetafieldsAction  $action  Owner sync action
     * @return JsonResponse Synced owner metafields payload
     */
    private function syncOwner(
        Model $owner,
        SyncOwnerMetafieldsPayload $data,
        SyncOwnerMetafieldsAction $action,
    ): JsonResponse {
        $this->authorization->authorizeOwner(MetafieldAbility::MutateOwner, $owner);
        $metafields = $action->execute($owner, $data);
        $ownerType = $this->ownerRegistry->resolveOwnerType($owner);

        return response()->json([
            'data' => [
                'items' => $metafields
                    ->map(
                        static fn (Metafield $metafield): array => OwnerMetafieldValue::fromModel(
                            $metafield,
                            $ownerType,
                        )->toArray(),
                    )
                    ->values()
                    ->all(),
                'meta' => [
                    'total' => $metafields->count(),
                    'ownerId' => $this->ownerIdentifier($owner),
                    'ownerType' => $ownerType,
                ],
            ],
        ], 200);
    }

    /**
     * Delete an owner metafield and return the canonical API payload.
     *
     * @param  Model  $owner  Owner model losing the metafield value
     * @param  MetafieldDefinition  $definition  Definition being cleared
     * @param  DeleteOwnerMetafieldPayload  $data  Revision-aware deletion payload
     * @param  DeleteOwnerMetafieldAction  $action  Owner delete action
     * @return JsonResponse Deletion result payload
     */
    private function deleteOwnerMetafield(
        Model $owner,
        MetafieldDefinition $definition,
        DeleteOwnerMetafieldPayload $data,
        DeleteOwnerMetafieldAction $action,
    ): JsonResponse {
        $this->authorization->authorizeOwner(
            MetafieldAbility::DeleteOwnerValue,
            $owner,
            $definition,
        );

        return response()->json([
            'data' => [
                'deleted' => $action->execute($owner, $definition, $data->expectedRevision),
            ],
        ], 200);
    }

    /**
     * Build the canonical owner identity payload.
     *
     * @param  Model  $owner  Metafield owner model
     * @return array<string, mixed> Owner identity payload
     */
    private function ownerPayload(Model $owner): array
    {
        return [
            'id' => $this->ownerIdentifier($owner),
            'type' => $this->ownerRegistry->resolveOwnerType($owner),
        ];
    }

    private function ownerIdentifier(Model $owner): string
    {
        $identifier = $owner->getKey();

        if (! is_string($identifier) && ! is_int($identifier)) {
            throw new \LogicException('Metafield owners require a string or integer identifier.');
        }

        return (string) $identifier;
    }

    /**
     * Build one definition with assignment metadata.
     *
     * @param  MetafieldDefinition  $definition  Definition to transform
     * @return array<string, mixed> Definition and assignment payload
     */
    private function definitionPayload(MetafieldDefinition $definition): array
    {
        $definition->loadMissing('assignments');

        return [
            'id' => $definition->id,
            'handle' => $definition->handle,
            'revision' => $definition->revision,
            'definition' => MetafieldDefinitionPayload::fromModel($definition)->toArray(),
            'assignments' => $definition->assignments
                ->map(static fn (MetafieldDefinitionAssignment $assignment): array => MetafieldDefinitionAssignmentPayload::fromModel($assignment)->toArray())
                ->values()
                ->all(),
        ];
    }
}
