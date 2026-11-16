<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use Nvl\Metafields\Adoption\LegacyMetafieldGraph;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Services\MetafieldDefinitions\MetafieldDefinitionInput;
use Nvl\Metafields\Services\Metafields\MetafieldReferenceRecordResolver;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Enums\SystemActor;

/**
 * Stages an existing standalone metafield graph with its original identities.
 *
 * @api
 *
 * @phpstan-type Source array{connection?:string, owner_types?:array<string,string>, owner_morphs?:array<string,string>, owner_ids?:array<string,array<string,string>>, reference_types?:array<string,string>, reference_ids?:array<string,array<string,string>>}
 * @phpstan-type Review array{schema_fingerprint:string,fingerprint:string,source:array<string,int>,staged:array<string,int>,conflicts:list<string>}
 */
final readonly class LegacyMetafieldImporter
{
    private LegacyMetafieldGraph $graph;

    public function __construct(
        DatabaseManager $database,
        private MetafieldActorPolicy $actors,
        private LocaleCatalog $locales,
        MetafieldOwnerRegistry $owners,
        MetafieldDefinitionInput $definitionInput,
        MetafieldReferenceRecordResolver $references,
    ) {
        $this->graph = new LegacyMetafieldGraph($database, $locales, $owners, $definitionInput, $references);
    }

    /**
     * Review the existing graph and translations without writing to either database.
     *
     * @param  Source  $source
     * @return Review
     */
    public function plan(Authenticatable|SystemActor $actor, string $sourceLocale, array $source = []): array
    {
        $this->admit($actor, $sourceLocale);

        return $this->inspect($sourceLocale, $source);
    }

    /**
     * Stage reviewed rows in resumable committed batches.
     *
     * @param  Source  $source
     * @return Review
     */
    public function apply(Authenticatable|SystemActor $actor, string $sourceLocale, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $sourceLocale, committed: true);
        $review = $this->inspect($sourceLocale, $source);
        $this->assertReviewed($review, $fingerprint);
        if ($review['source'] !== $review['staged']) {
            $this->assertReviewed($this->inspect($sourceLocale, $source, write: true), $fingerprint);
            $review = $this->inspect($sourceLocale, $source);
        }

        return $this->reconciled($review, $fingerprint);
    }

    /**
     * Reconcile the committed graph before the host activates native reads and writes.
     *
     * @param  Source  $source
     * @return Review
     */
    public function verify(Authenticatable|SystemActor $actor, string $sourceLocale, string $fingerprint, array $source = []): array
    {
        $this->admit($actor, $sourceLocale, committed: true);

        return $this->reconciled($this->inspect($sourceLocale, $source), $fingerprint);
    }

    private function admit(Authenticatable|SystemActor $actor, string $locale, bool $committed = false): void
    {
        $this->actors->authorize($actor, 'import');
        if (MetafieldOwnership::declared() || ($committed && MetafieldModelRegistry::newInstance()->getConnection()->transactionLevel() !== 0)) {
            throw new LogicException('Metafield adoption requires standalone storage and a committed target before switching to native Ownership or production use.');
        }
        if (! in_array(MetafieldModelRegistry::newInstance()->getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Legacy Metafield history requires partial indexes on PostgreSQL or SQLite.');
        }
        if (! $this->locales->supports($locale)) {
            throw new InvalidArgumentException('The legacy definition locale must be supported by the destination.');
        }
    }

    /**
     * @param  Source  $source
     * @return Review
     */
    private function inspect(string $locale, array $source, bool $write = false): array
    {
        $inspection = $this->graph->inspect($locale, $source, $write);

        return [
            'schema_fingerprint' => $inspection['schema_fingerprint'], 'fingerprint' => $inspection['fingerprint'],
            'source' => $inspection['source'], 'staged' => $inspection['staged'], 'conflicts' => $inspection['conflicts'],
        ];
    }

    /** @param Review $review */
    private function assertReviewed(array $review, string $fingerprint): void
    {
        if (! hash_equals($fingerprint, $review['fingerprint'])) {
            throw new LogicException('Metafield adoption fingerprint changed. Review a new plan.');
        }
        if ($review['conflicts'] !== []) {
            throw new LogicException($review['conflicts'][0]);
        }
    }

    /**
     * @param  Review  $review
     * @return Review
     */
    private function reconciled(array $review, string $fingerprint): array
    {
        $this->assertReviewed($review, $fingerprint);
        if ($review['source'] !== $review['staged']) {
            throw new LogicException('Metafield adoption reconciliation is incomplete.');
        }

        return $review;
    }
}
