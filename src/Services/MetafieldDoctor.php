<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Contracts\MetafieldDiagnosticAccess;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Support\MetafieldOwnerPredicate;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Nvl\Metafields\Support\ModelIdentifier;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipException;

/**
 * Read-only package installation diagnostics.
 *
 * @internal
 */
final readonly class MetafieldDoctor
{
    public function __construct(private MetafieldOwnerRegistry $owners, private MetafieldDiagnosticAccess $diagnostics) {}

    /** @return list<array{key: string, severity: string, passed: bool, message: string}> */
    public function inspect(): array
    {
        $connection = (new Metafield)->getConnection();
        $schema = $connection->getSchemaBuilder();
        $checks = [];
        $checks[] = ['key' => 'schema.source', 'severity' => 'error', 'passed' => MetafieldModelRegistry::newInstance()->getConnection() === $connection, 'message' => 'Definitions and values require the same actual package source.'];
        $valuesExist = false;
        foreach ([MetafieldsTables::Definitions, MetafieldsTables::DefinitionAssignments, MetafieldsTables::DefinitionsI18n, MetafieldsTables::Metafields, MetafieldsTables::I18n] as $logical) {
            $table = MetafieldsTables::get($logical);
            $exists = $connection->transaction(fn (): bool => $schema->hasTable($table));
            if ($logical === MetafieldsTables::Metafields) {
                $valuesExist = $exists;
            }
            $checks[] = ['key' => "schema.table.{$table}", 'severity' => 'error', 'passed' => $exists, 'message' => $exists ? "Table [{$table}] exists." : "Table [{$table}] is missing."];
        }
        $checks[] = ['key' => 'authorization.owner', 'severity' => 'error', 'passed' => app()->bound(MetafieldAuthorization::class), 'message' => 'Owner authorization binding is available.'];
        /** @var MetafieldBatchAuthorization $batch */
        $batch = app()->make(MetafieldBatchAuthorization::class);
        $checks[] = ['key' => 'authorization.batch', 'severity' => 'warning', 'passed' => ! $batch instanceof UnsupportedMetafieldBatchAuthorization, 'message' => 'A SQL-visible batch authorization adapter is required for owner batches.'];
        $checks[] = ['key' => 'registry.owners', 'severity' => 'warning', 'passed' => $this->owners->all() !== [], 'message' => 'Registered owner models are available.'];
        try {
            $references = MetafieldReferenceModelRegistry::all();
            $checks[] = ['key' => 'registry.references', 'severity' => 'warning', 'passed' => $references !== [], 'message' => 'Registered reference models are available.'];
        } catch (InvalidArgumentException $exception) {
            $checks[] = ['key' => 'registry.references', 'severity' => 'error', 'passed' => false, 'message' => $exception->getMessage()];
        }
        if ($valuesExist) {
            $indexes = $schema->getIndexes(MetafieldsTables::get(MetafieldsTables::Metafields));
            $covered = array_any($indexes, static fn (array $index): bool => array_slice($index['columns'], 0, 2) === ['definition_id', 'metafieldable_type']);
            $checks[] = ['key' => 'schema.index.definition_owner', 'severity' => 'warning', 'passed' => $covered, 'message' => 'Definition-first value lookups require a definition_id/metafieldable_type leading index.'];
        }
        $checks[] = $this->ownerReferences($valuesExist);

        return $checks;
    }

    /** @return array{key:string,severity:string,passed:bool,message:string} */
    private function ownerReferences(bool $valuesExist): array
    {
        $check = ['key' => 'data.owner_references', 'severity' => 'warning', 'passed' => false, 'message' => 'Owner-reference sampling skipped; no references inspected. An explicit MetafieldDiagnosticAccess read grant for SystemActor::Background is required.'];
        if (! $this->diagnostics->allows(SystemActor::Background)) {
            return $check;
        }
        if (! $valuesExist) {
            $check['message'] = 'Owner-reference sampling requires installed value storage.';

            return $check;
        }
        try {
            $prototype = new Metafield;
            $sample = Metafield::withTrashed()->useWritePdo()->orderBy('id')->limit(101)->get(['id', 'metafieldable_type', 'metafieldable_id']);
            $partial = $sample->count() > 100;
            $sample = $sample->take(100);
            $models = [];
            foreach ($this->owners->all() as $owner) {
                if ($owner['runtime_status'] === 'live') {
                    $model = new ($owner['model']);
                    $models[$model->getMorphClass()] = $model;
                }
            }
            $unresolved = 0;
            foreach ($sample->groupBy('metafieldable_type') as $type => $records) {
                $identifiers = array_values($records->map(static fn (Metafield $value): string => $value->metafieldable_id)->unique()->all());
                $model = $models[$type] ?? null;
                if ($model === null || $model->getConnection() !== $prototype->getConnection()) {
                    $unresolved += count($identifiers);

                    continue;
                }
                $query = $model->newQuery()->withoutGlobalScope(SoftDeletingScope::class)->withoutEagerLoads()->useWritePdo();
                MetafieldOwnerPredicate::identifiers($query, $model->getQualifiedKeyName(), $identifiers);
                $found = $query->get([$model->getQualifiedKeyName()])->map(static fn (Model $owner): string => ModelIdentifier::required($owner))->all();
                $unresolved += count(array_diff($identifiers, $found));
            }
            $check['passed'] = $unresolved === 0;
            $check['message'] = 'Sampled '.$sample->count().' visible value references'.($partial ? ' (more rows remain)' : '').'; '.$unresolved.' unresolved owner references within host scopes. Scoped-out or unregistered records are not proven deleted.';
        } catch (OwnershipException|QueryException) {
            $check['message'] = 'Owner-reference sampling skipped in the current admitted scope or source; no global orphan result is asserted.';
        }

        return $check;
    }
}
