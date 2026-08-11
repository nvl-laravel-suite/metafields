<?php

declare(strict_types=1);

namespace Nvl\Metafields\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Contracts\MetafieldAuthorization;
use Nvl\Metafields\Contracts\MetafieldReferenceAuthorization;
use Nvl\Metafields\Data\MetafieldDoctorCheckData;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Support\MetafieldOwnerRegistry;
use Nvl\Metafields\Support\MetafieldReferenceModelRegistry;
use Throwable;

/**
 * Inspects schema and runtime bindings without mutating consumer state.
 */
final readonly class MetafieldDoctor
{
    public function __construct(
        private Container $container,
        private MetafieldOwnerRegistry $owners,
    ) {}

    /**
     * @return list<MetafieldDoctorCheckData>
     */
    public function inspect(): array
    {
        return [
            ...$this->schemaChecks(),
            $this->authorizationCheck(),
            $this->referenceAuthorizationCheck(),
            $this->routeCheck(),
            $this->ownerRegistryCheck(),
            $this->referenceRegistryCheck(),
        ];
    }

    /**
     * @return list<MetafieldDoctorCheckData>
     */
    private function schemaChecks(): array
    {
        $tables = [
            MetafieldsTables::Definitions => [
                'id', 'namespace', 'key', 'handle', 'active_handle', 'type',
                'revision', 'archived_at', 'deleted_at',
            ],
            MetafieldsTables::Metafields => [
                'id', 'definition_id', 'metafieldable_type', 'metafieldable_id',
                'value', 'referenced_id', 'revision', 'deleted_at',
            ],
            MetafieldsTables::I18n => [
                'id', 'metafield_id', 'locale', 'value',
            ],
            MetafieldsTables::DefinitionsI18n => [
                'id', 'metafield_definition_id', 'locale', 'title',
            ],
            MetafieldsTables::DefinitionAssignments => [
                'id', 'definition_id', 'owner_type', 'section', 'is_active',
            ],
        ];

        $checks = [];

        foreach ($tables as $table => $columns) {
            $exists = Schema::hasTable($table);
            $checks[] = new MetafieldDoctorCheckData(
                key: "schema.table.{$table}",
                severity: 'error',
                passed: $exists,
                message: $exists ? "Table [{$table}] exists." : "Table [{$table}] is missing.",
            );

            if (! $exists) {
                continue;
            }

            $missing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => ! Schema::hasColumn($table, $column),
            ));
            $checks[] = new MetafieldDoctorCheckData(
                key: "schema.columns.{$table}",
                severity: 'error',
                passed: $missing === [],
                message: $missing === []
                    ? "Required columns exist on [{$table}]."
                    : 'Missing columns: '.implode(', ', $missing).'.',
            );
        }

        $indexes = [
            [
                MetafieldsTables::Definitions,
                'metafields_definitions_active_handle_unique',
                ['active_handle'],
                true,
            ],
            [
                MetafieldsTables::Metafields,
                'metafields_owner_definition_unique',
                ['metafieldable_type', 'metafieldable_id', 'definition_id'],
                true,
            ],
            [
                MetafieldsTables::DefinitionAssignments,
                'metafield_definition_assignments_unique',
                ['definition_id', 'owner_type'],
                true,
            ],
            [
                MetafieldsTables::DefinitionAssignments,
                'metafield_assignment_owner_active_section_idx',
                ['owner_type', 'is_active', 'section'],
                false,
            ],
        ];

        foreach ($indexes as [$table, $name, $columns, $unique]) {
            $checks[] = $this->indexCheck($table, $name, $columns, $unique);
        }

        return $checks;
    }

    private function authorizationCheck(): MetafieldDoctorCheckData
    {
        $bound = $this->container->bound(MetafieldAuthorization::class);

        return new MetafieldDoctorCheckData(
            key: 'binding.authorization',
            severity: 'error',
            passed: $bound,
            message: $bound
                ? 'The Metafield authorization boundary is bound.'
                : 'No MetafieldAuthorization implementation is bound.',
        );
    }

    private function referenceAuthorizationCheck(): MetafieldDoctorCheckData
    {
        $bound = $this->container->bound(MetafieldReferenceAuthorization::class);

        return new MetafieldDoctorCheckData(
            key: 'binding.reference_authorization',
            severity: 'error',
            passed: $bound,
            message: $bound
                ? 'The Metafield reference authorization boundary is bound.'
                : 'No MetafieldReferenceAuthorization implementation is bound.',
        );
    }

    /**
     * Verify one index by name, ordered columns, and uniqueness.
     *
     * @param  list<string>  $columns
     */
    private function indexCheck(
        string $table,
        string $name,
        array $columns,
        bool $unique,
    ): MetafieldDoctorCheckData {
        if (! Schema::hasTable($table)) {
            return new MetafieldDoctorCheckData(
                key: "schema.index.{$name}",
                severity: 'error',
                passed: false,
                message: "Index [{$name}] is missing.",
            );
        }

        /** @var array<int, array{name: string, columns: list<string>, unique: bool}> $indexes */
        $indexes = Schema::getIndexes($table);
        $index = collect($indexes)->firstWhere('name', $name);
        $passed = is_array($index)
            && $index['columns'] === $columns
            && $index['unique'] === $unique;

        return new MetafieldDoctorCheckData(
            key: "schema.index.{$name}",
            severity: 'error',
            passed: $passed,
            message: $passed
                ? "Index [{$name}] has the expected ordered columns and uniqueness."
                : "Index [{$name}] does not match the expected schema.",
        );
    }

    private function routeCheck(): MetafieldDoctorCheckData
    {
        if (! (bool) config('metafields.routes.enabled', false)) {
            return new MetafieldDoctorCheckData(
                key: 'routes.management',
                severity: 'warning',
                passed: true,
                message: 'Management routes are disabled.',
            );
        }

        $middleware = array_values(array_filter(
            (array) config('metafields.routes.management_middleware', []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        $hasAuthentication = collect($middleware)->contains(
            static fn (string $value): bool => $value === 'auth' || str_starts_with($value, 'auth:'),
        );
        $hasRateLimit = collect($middleware)->contains(
            static fn (string $value): bool => str_starts_with($value, 'throttle:'),
        );
        $routeRegistered = Route::has('nvl.metafields.management.definitions.index');
        $passed = $hasAuthentication && $hasRateLimit && $routeRegistered;

        return new MetafieldDoctorCheckData(
            key: 'routes.management',
            severity: 'error',
            passed: $passed,
            message: match (true) {
                ! $hasAuthentication => 'Management routes are enabled without authentication middleware.',
                ! $hasRateLimit => 'Management routes are enabled without rate limiting.',
                ! $routeRegistered => 'Management routes are enabled but were not registered.',
                default => 'Management routes are enabled with authentication and rate limiting.',
            },
        );
    }

    private function ownerRegistryCheck(): MetafieldDoctorCheckData
    {
        try {
            foreach (array_keys((array) config('metafields.owners', [])) as $alias) {
                if (is_string($alias)) {
                    $this->owners->forType($alias);
                }
            }

            return new MetafieldDoctorCheckData(
                key: 'registry.owners',
                severity: 'error',
                passed: true,
                message: 'Configured owner aliases are valid.',
            );
        } catch (Throwable $exception) {
            return new MetafieldDoctorCheckData(
                key: 'registry.owners',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }

    private function referenceRegistryCheck(): MetafieldDoctorCheckData
    {
        try {
            MetafieldReferenceModelRegistry::all();

            return new MetafieldDoctorCheckData(
                key: 'registry.references',
                severity: 'error',
                passed: true,
                message: 'Configured reference aliases are valid.',
            );
        } catch (Throwable $exception) {
            return new MetafieldDoctorCheckData(
                key: 'registry.references',
                severity: 'error',
                passed: false,
                message: $exception->getMessage(),
            );
        }
    }
}
