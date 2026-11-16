<?php

declare(strict_types=1);

namespace Nvl\Metafields\Adoption;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use InvalidArgumentException;
use LogicException;
use Nvl\Metafields\Ownership\MetafieldOwnership;
use Nvl\Metafields\Services\LegacyMetafieldImporter;
use Nvl\Metafields\Services\MetafieldActorPolicy;
use Nvl\Support\Adoption\AdoptionAdapter;
use Nvl\Support\Adoption\AdoptionBatch;
use Nvl\Support\Adoption\AdoptionException;
use Nvl\Support\Adoption\AdoptionPlan;
use Nvl\Support\Adoption\AdoptionStep;
use Nvl\Support\Adoption\AdoptionVerification;
use Nvl\Support\Contracts\LocaleCatalog;
use Nvl\Support\Enums\SystemActor;

/**
 * Adopts the legacy Metafield graph through `nvl:adopt metafields` in checkpointed batches.
 *
 * Steps run definitions with their source-locale copy, then assignments, values and value translations. A definition
 * and its copy always commit in the same batch. The manifest holds `locale` plus the {@see LegacyMetafieldImporter}
 * source options.
 *
 * @internal
 *
 * @phpstan-import-type Source from LegacyMetafieldImporter
 */
final class LegacyMetafieldAdoptionAdapter extends AdoptionAdapter
{
    /** Version of the mapping and batch layout; bump it when either changes. */
    public const string Version = '1';

    private const array MapOptions = ['owner_types', 'owner_morphs', 'reference_types'];

    private const array IdentityOptions = ['owner_ids', 'reference_ids'];

    private readonly string $locale;

    /** @var Source */
    private readonly array $source;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        Authenticatable|SystemActor $actor,
        array $options,
        private readonly MetafieldActorPolicy $actors,
        private readonly LocaleCatalog $locales,
        private readonly LegacyMetafieldGraph $graph,
    ) {
        parent::__construct($actor, $options);
        $locale = $options['locale'] ?? null;
        if (! is_string($locale) || $locale === '') {
            throw new InvalidArgumentException('Metafield adoption requires the source definition locale as the "locale" option.');
        }
        $this->locale = $locale;
        $this->source = self::source($options);
    }

    public function connection(): Connection
    {
        return $this->graph->target();
    }

    public function authorize(string $phase): void
    {
        $this->actors->authorize($this->actor, 'import');
    }

    public function plan(): AdoptionPlan
    {
        $this->admit();
        $inspection = $this->graph->inspect($this->locale, $this->source);
        $origin = $this->graph->origin($this->source);
        $target = $this->graph->target();

        return new AdoptionPlan(
            package: 'metafields',
            adapterVersion: self::Version,
            fingerprint: AdoptionPlan::digest(['mapping' => self::Version, 'graph' => $inspection['fingerprint'], 'options' => $this->options]),
            source: ['connection' => $origin->getName(), 'database' => $origin->getDatabaseName(), 'prefix' => $origin->getTablePrefix()],
            destination: ['connection' => $target->getName(), 'database' => $target->getDatabaseName(), 'prefix' => $target->getTablePrefix()],
            optionsDigest: AdoptionPlan::digest($this->options),
            steps: [
                new AdoptionStep('definitions'),
                new AdoptionStep('assignments', ['definitions']),
                new AdoptionStep('values', ['definitions']),
                new AdoptionStep('value_translations', ['values']),
            ],
            expected: array_intersect_key($inspection['source'], array_flip(LegacyMetafieldGraph::Kinds)),
            collisions: $inspection['conflicts'],
        );
    }

    public function nextBatch(AdoptionStep $step, ?array $cursor, int $limit): AdoptionBatch
    {
        $origin = $this->graph->origin($this->source);
        $rows = $step->page($origin->table($this->graph->legacyTable($step->id))->useWritePdo(), $cursor, $limit + 1)->get()->values()->all();
        $page = array_slice($rows, 0, $limit);
        $mapped = [];
        foreach ($this->graph->mapPage($origin, $step->id, $page, $this->locale, $this->source) as $entry) {
            if ($entry['error'] !== null || $entry['mapped'] === null) {
                throw new AdoptionException('Metafield adoption collision: '.$entry['error']);
            }
            $mapped[] = ['row' => $entry['mapped'], 'copy' => $entry['copy']];
        }
        $last = $page === [] ? null : $page[array_key_last($page)];

        return new AdoptionBatch($step->id, $cursor, $last === null ? $cursor : $step->cursorFrom($last), $mapped, count($rows) <= $limit);
    }

    public function writeBatch(AdoptionBatch $batch): ?string
    {
        $rows = $copies = [];
        foreach ($batch->rows as $entry) {
            $rows[] = self::attributes($batch->step, $entry['row'] ?? null);
            if (($entry['copy'] ?? null) !== null) {
                $copies[] = self::attributes($batch->step, $entry['copy']);
            }
        }
        $this->graph->stage($batch->step, $rows, $copies, $this->locale);

        return null;
    }

    public function verify(AdoptionPlan $plan): AdoptionVerification
    {
        $this->admit();
        $inspection = $this->graph->inspect($this->locale, $this->source);

        return new AdoptionVerification($inspection['missing'], $inspection['extra'], $inspection['changed'], $inspection['destination'], $inspection['conflicts']);
    }

    /**
     * Apply the importer's storage and locale admission to every phase.
     */
    private function admit(): void
    {
        if (MetafieldOwnership::declared()) {
            throw new LogicException('Metafield adoption requires standalone storage before switching to native Ownership or production use.');
        }
        if (! in_array($this->graph->target()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            throw new LogicException('Legacy Metafield history requires partial indexes on PostgreSQL or SQLite.');
        }
        if (! $this->locales->supports($this->locale)) {
            throw new InvalidArgumentException('The legacy definition locale must be supported by the destination.');
        }
    }

    /**
     * Read the importer source options from the manifest and reject anything else.
     *
     * @param  array<string, mixed>  $options
     * @return Source
     */
    private static function source(array $options): array
    {
        $source = [];
        foreach ($options as $option => $value) {
            if ($option === 'locale') {
                continue;
            }
            if ($option === 'connection') {
                $source['connection'] = is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException('The Metafield adoption "connection" option must name a connection.');
            } elseif (in_array($option, self::MapOptions, true)) {
                $source[$option] = self::strings($option, $value);
            } elseif (in_array($option, self::IdentityOptions, true)) {
                $source[$option] = [];
                foreach (is_array($value) ? $value : throw new InvalidArgumentException("The Metafield adoption \"{$option}\" option must map types to identifiers.") as $type => $identifiers) {
                    $source[$option][(string) $type] = self::strings($option, $identifiers);
                }
            } else {
                throw new InvalidArgumentException("Unknown Metafield adoption option [{$option}].");
            }
        }

        return $source;
    }

    /**
     * Read one mapped row of a batch back as named attributes.
     *
     * @return array<string, mixed>
     */
    private static function attributes(string $step, mixed $row): array
    {
        if (! is_array($row)) {
            throw new AdoptionException("Metafield adoption batch for [{$step}] holds an unmapped row.");
        }
        $attributes = [];
        foreach ($row as $column => $value) {
            $attributes[is_string($column) ? $column : throw new AdoptionException("Metafield adoption batch for [{$step}] holds an unnamed column.")] = $value;
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(string $option, mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("The Metafield adoption \"{$option}\" option must be a map of strings.");
        }
        $strings = [];
        foreach ($value as $from => $to) {
            if ((! is_string($to) && ! is_int($to)) || $to === '') {
                throw new InvalidArgumentException("The Metafield adoption \"{$option}\" option must be a map of strings.");
            }
            $strings[(string) $from] = (string) $to;
        }

        return $strings;
    }
}
