<?php

declare(strict_types=1);

namespace Nvl\Metafields\Actions\Metafields;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\Metafields\Contracts\SetMetafieldContract;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Events\MetafieldSetEvent;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionCatalog;
use RuntimeException;

/**
 * Sets a single owner metafield through the bulk owner sync contract.
 *
 * This action preserves the singular `HasMetafields` write API while keeping
 * validation, assignment checks, translation merging, and upsert behavior inside
 * the shared owner sync pipeline.
 */
final class SetMetafieldAction implements SetMetafieldContract
{
    public function __construct(
        private readonly MetafieldDefinitionCatalog $definitionCatalog,
        private readonly SyncOwnerMetafieldsAction $syncOwnerMetafieldsAction,
    ) {}

    /**
     * Set a metafield by handle for the given owner.
     *
     * @param  Model  $owner  Owner receiving the metafield value
     * @param  string  $handle  Definition handle in namespace.key format
     * @param  mixed  $value  Raw value to validate and store
     * @param  string|null  $locale  Locale required for translatable definitions
     * @return Metafield Persisted owner metafield row
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function execute(
        Model $owner,
        string $handle,
        mixed $value,
        ?string $locale = null,
        ?int $expectedRevision = null,
    ): Metafield {
        $definition = $this->definitionCatalog->findByHandle($handle);

        if (! $definition instanceof MetafieldDefinition) {
            throw new InvalidArgumentException("Metafield definition not found: {$handle}");
        }

        $item = ['definitionId' => $definition->id];

        if ($definition->is_translatable) {
            if (! is_string($locale) || $locale === '') {
                throw new InvalidArgumentException(
                    "A locale is required when setting translatable metafield [{$handle}].",
                );
            }

            $item['translations'] = [$locale => $value];
        } else {
            $item['value'] = $value;
        }

        if (is_int($expectedRevision)) {
            $item['expectedRevision'] = $expectedRevision;
        }

        /** @var Metafield|null $metafield */
        $metafield = $this->syncOwnerMetafieldsAction
            ->execute($owner, SyncOwnerMetafieldsPayload::from(['items' => [$item]]))
            ->first();

        if (! $metafield instanceof Metafield) {
            throw new RuntimeException("Metafield sync did not return a persisted row for [{$handle}].");
        }

        MetafieldSetEvent::dispatch($metafield);

        return $metafield;
    }
}
