<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use InvalidArgumentException;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldBatchAuthorization;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;

/** Read-only package installation diagnostics. */
final readonly class MetafieldDoctor
{
    public function __construct(private MetafieldOwnerRegistry $owners) {}

    /** @return list<array{key: string, severity: string, passed: bool, message: string}> */
    public function inspect(): array
    {
        $schema = MetafieldModelRegistry::newInstance()->getConnection()->getSchemaBuilder();
        $checks = [];
        foreach ([MetafieldsTables::Definitions, MetafieldsTables::DefinitionAssignments, MetafieldsTables::DefinitionsI18n, MetafieldsTables::Metafields, MetafieldsTables::I18n] as $logical) {
            $table = MetafieldsTables::get($logical);
            $exists = $schema->hasTable($table);
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

        return $checks;
    }
}
