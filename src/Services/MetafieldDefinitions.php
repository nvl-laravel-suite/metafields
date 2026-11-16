<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldDefinitionOwnerGrant;
use Nvl\Metafields\Ownership\MetafieldOwnerCatalog;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionAssignmentSyncer;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionInput;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionMutationGuard;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionRemover;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionWriter;
use Nvl\Support\Enums\SystemActor;

/**
 * Complete native definition mutations and explicit catalog grants.
 *
 * @api
 *
 * @phpstan-import-type CatalogImport from MetafieldOwnerCatalog
 */
final readonly class MetafieldDefinitions
{
    public function __construct(
        private MetafieldActorPolicy $actors,
        private MetafieldAuthorization $authorization,
        private MetafieldDefinitionInput $input,
        private MetafieldDefinitionWriter $writer,
        private MetafieldDefinitionAssignmentSyncer $assignments,
        private MetafieldDefinitionMutationGuard $guard,
        private MetafieldDefinitionRemover $remover,
        private MetafieldOwnerCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, Authenticatable|SystemActor $actor): MetafieldDefinition
    {
        $actor = $this->actors->authorize($actor, 'create');
        $this->authorization->authorizeDefinition($actor, MetafieldAbility::CreateDefinition);
        $attributes = $this->input->validate($attributes, true);

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(function () use ($attributes): MetafieldDefinition {
            $this->assertHandleAvailable($attributes['namespace'], $attributes['key']);
            $definition = $this->writer->create($attributes);
            $this->assignments->sync($definition, $attributes['assignment'] ?? throw new \LogicException('Creation requires a validated assignment.'));

            return $definition->refresh()->load(['assignments', 'translations']);
        });
    }

    /** Report a handle another active definition holds; the unique index still decides a concurrent race. */
    private function assertHandleAvailable(string $namespace, string $key, ?string $exceptId = null): void
    {
        $taken = MetafieldModelRegistry::query()->active()->where('active_handle', MetafieldDefinition::generateHandle($namespace, $key));
        if ($exceptId !== null) {
            $taken->whereKeyNot($exceptId);
        }
        if ($taken->exists()) {
            throw ValidationException::withMessages(['key' => [trans('nvl-metafields::metafields/validation.custom.definition.handle_taken')]]);
        }
    }

    /** Lock the canonical definition, treating an identifier its key cannot hold as not found. */
    private function locked(string $id): MetafieldDefinition
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(MetafieldModelRegistry::modelClass(), [$id]);
        }

        return MetafieldModelRegistry::query()->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $attributes */
    public function update(MetafieldDefinition|string $definition, array $attributes, int $expectedRevision, Authenticatable|SystemActor $actor): MetafieldDefinition
    {
        $actor = $this->actors->authorize($actor, 'update');
        $id = $definition instanceof MetafieldDefinition ? $definition->id : $definition;

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(function () use ($id, $attributes, $expectedRevision, $actor): MetafieldDefinition {
            $definition = $this->locked($id);
            $this->authorization->authorizeDefinition($actor, MetafieldAbility::UpdateDefinition, $definition);
            if ($definition->revision !== $expectedRevision) {
                throw StaleMetafieldVersionException::forResource('metafield definition', $definition->id);
            }
            $attributes += [
                'type' => $definition->type,
                'namespace' => $definition->namespace,
                'key' => $definition->key,
                'isTranslatable' => $definition->is_translatable,
            ];
            if (! array_key_exists('referencedModelType', $attributes) && in_array($attributes['type'], [$definition->type, $definition->type->value], true)) {
                $attributes['referencedModelType'] = $definition->referenced_model_type;
            }
            $attributes = $this->input->validate($attributes, false);
            if ($definition->archived_at === null) {
                $this->assertHandleAvailable($attributes['namespace'], $attributes['key'], $definition->id);
            }
            $this->guard->ensureUpdateIsSafe($definition, $attributes);
            $definition = $this->writer->update($definition, $attributes);
            if (array_key_exists('assignment', $attributes)) {
                $this->assignments->sync($definition, $attributes['assignment']);
            }

            return $definition->refresh()->load(['assignments', 'translations']);
        });
    }

    /** Archive or restore a definition with a revision check. */
    public function archive(MetafieldDefinition|string $definition, bool $archived, int $expectedRevision, Authenticatable|SystemActor $actor): MetafieldDefinition
    {
        $actor = $this->actors->authorize($actor, 'archive');
        $id = $definition instanceof MetafieldDefinition ? $definition->id : $definition;

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(function () use ($id, $archived, $expectedRevision, $actor): MetafieldDefinition {
            $definition = $this->locked($id);
            $this->authorization->authorizeDefinition($actor, MetafieldAbility::UpdateDefinition, $definition);
            if ($definition->revision !== $expectedRevision) {
                throw StaleMetafieldVersionException::forResource('metafield definition', $definition->id);
            }
            if (! $archived && MetafieldModelRegistry::query()->active()->where('active_handle', $definition->handle)->where('id', '!=', $definition->id)->exists()) {
                throw ValidationException::withMessages(['archived' => [trans('nvl-metafields::metafields/validation.custom.definition.active_handle_conflict')]]);
            }
            $definition->archived_at = $archived ? now() : null;
            $definition->save();

            return $definition->refresh()->load(['assignments', 'translations']);
        });
    }

    /** Soft-delete a definition and optionally its values. */
    public function delete(MetafieldDefinition|string $definition, int $expectedRevision, Authenticatable|SystemActor $actor, bool $deleteValues = false): bool
    {
        $actor = $this->actors->authorize($actor, 'delete');
        $id = $definition instanceof MetafieldDefinition ? $definition->id : $definition;

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(function () use ($id, $expectedRevision, $deleteValues, $actor): bool {
            $definition = $this->locked($id);
            $this->authorization->authorizeDefinition($actor, MetafieldAbility::DeleteDefinition, $definition);
            if ($definition->revision !== $expectedRevision) {
                throw StaleMetafieldVersionException::forResource('metafield definition', $definition->id);
            }

            return $this->remover->delete($definition, $deleteValues);
        });
    }

    /** Grant one immutable source revision to the admitted native owner context. */
    public function grant(string $definitionId, int $sourceRevision, Authenticatable|SystemActor $actor): MetafieldDefinitionOwnerGrant
    {
        $this->actors->authorize($actor, 'grant');

        return $this->catalog->grant($definitionId, $sourceRevision);
    }

    /** Revoke an existing grant with its expected revision. */
    public function revoke(string $grantId, int $expectedRevision, Authenticatable|SystemActor $actor): MetafieldDefinitionOwnerGrant
    {
        $this->actors->authorize($actor, 'revoke');

        return $this->catalog->revoke($grantId, $expectedRevision);
    }

    /**
     * Import an exact granted central definition into the admitted native owner context.
     *
     * @param  array<string, mixed>  $request
     */
    public function import(array $request, Authenticatable|SystemActor $actor): MetafieldDefinition
    {
        $this->actors->authorize($actor, 'import');
        Validator::make($request, [
            'grantId' => ['required', 'uuid'],
            'expectedGrantRevision' => ['required', 'integer', 'min:1'],
            'expectedSourceRevision' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'namespace' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_-]+$/'],
            'assignment' => ['required', 'array:ownerType,section,displayOrder,isRequired,isActive,uiConfig,ownerScopes'],
            'assignment.ownerType' => ['required', 'string'],
            'assignment.section' => ['required', 'string', 'max:255'],
            'referenceMap' => ['sometimes', 'array'],
            'referenceMap.*' => ['string'],
        ])->validate();
        if (array_diff(array_keys($request), ['grantId', 'expectedGrantRevision', 'expectedSourceRevision', 'idempotency_key', 'namespace', 'key', 'assignment', 'referenceMap']) !== []) {
            throw ValidationException::withMessages(['request' => ['Unknown import attributes are not accepted.']]);
        }
        $request['referenceMap'] ??= [];
        $grantRevision = filter_var($request['expectedGrantRevision'], FILTER_VALIDATE_INT);
        $sourceRevision = filter_var($request['expectedSourceRevision'], FILTER_VALIDATE_INT);
        if (! is_int($grantRevision) || ! is_int($sourceRevision)) {
            throw ValidationException::withMessages(['grantId' => ['Catalog revisions must be integers.']]);
        }
        $request['expectedGrantRevision'] = $grantRevision;
        $request['expectedSourceRevision'] = $sourceRevision;
        /** @var CatalogImport $request */
        $snapshot = $this->catalog->inspect($request['grantId']);
        if ($snapshot['grantRevision'] !== $request['expectedGrantRevision'] || $snapshot['sourceRevision'] !== $request['expectedSourceRevision']) {
            throw ValidationException::withMessages(['grantId' => ['The requested catalog revision is stale.']]);
        }
        $source = $snapshot['definition'];
        if (! array_key_exists('ownerScopes', $request['assignment']) && isset($source['scopedAssignments'])) {
            $sourceScopes = $source['scopedAssignments'][$request['assignment']['ownerType']] ?? null;
            if ($sourceScopes === null) {
                throw ValidationException::withMessages(['assignment.ownerScopes' => ['Map the source scopes explicitly when importing into another owner type.']]);
            }
            $request['assignment']['ownerScopes'] = $sourceScopes;
        }
        $validated = $this->input->validate([
            'type' => $source['type'],
            'referencedModelType' => $source['referencedModelType'],
            'isTranslatable' => $source['isTranslatable'],
            'isRequired' => $source['isRequired'],
            'isFilterable' => $source['isFilterable'],
            'validationRules' => $source['validationRules'],
            'jsonPropertySchema' => $source['jsonPropertySchema'],
            'displayOrder' => $source['displayOrder'],
            'translations' => $source['translations'],
            'namespace' => $request['namespace'],
            'key' => $request['key'],
            'assignment' => $request['assignment'],
        ], true);
        if (array_key_exists('ownerScopes', $request['assignment'])) {
            $request['assignment']['ownerScopes'] = $validated['assignment']['ownerScopes'] ?? null;
        }

        return MetafieldModelRegistry::newInstance()->getConnection()->transaction(
            fn (): MetafieldDefinition => $this->catalog->persist($snapshot, $request),
        );
    }
}
