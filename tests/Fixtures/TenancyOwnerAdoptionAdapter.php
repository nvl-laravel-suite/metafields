<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Tenancy\Contracts\TenantAdoptionAdapter;
use Nvl\Tenancy\ValueObjects\TenantAdoptionPlan;
use Nvl\Tenancy\ValueObjects\TenantBackfillResult;
use Nvl\Tenancy\ValueObjects\TenantVerification;
use RuntimeException;

/** Owns the empty canonical Metafield owner fixture schema. */
final readonly class TenancyOwnerAdoptionAdapter implements TenantAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['test.metafield-owners'];
    }

    /** Create the owner table with direct tenant identity. */
    public function prepare(TenantAdoptionPlan $plan): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable((new TestMetafieldOwner)->getTable())) {
            $schema->create((new TestMetafieldOwner)->getTable(), static function (Blueprint $table): void {
                $table->id();
                $table->uuid('tenant_id');
                $table->string('name');
                $table->timestamps();
                $table->unique(['tenant_id', 'id'], 'test_metafield_owners_tenant_id_unique');
            });
        }
    }

    /** Require every fixture owner to retain its reviewed tenant identity. */
    public function backfill(TenantAdoptionPlan $plan, ?string $cursor, int $limit): TenantBackfillResult
    {
        if ($this->connection()->table((new TestMetafieldOwner)->getTable())->whereNull('tenant_id')->exists()) {
            throw new RuntimeException('Metafield owner fixture adoption found an unmapped owner.');
        }

        return new TenantBackfillResult(null, 0);
    }

    /** Verify owner columns and partition uniqueness. */
    public function verify(TenantAdoptionPlan $plan): TenantVerification
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = (new TestMetafieldOwner)->getTable();
        $errors = [];
        if (! $schema->hasColumns($table, ['id', 'tenant_id', 'name', 'created_at', 'updated_at'])) {
            $errors[] = 'test.metafield-owners.columns';
        }
        if (! $schema->hasIndex($table, ['tenant_id', 'id'], 'unique')) {
            $errors[] = 'test.metafield-owners.identity';
        }

        return new TenantVerification($errors);
    }

    /** The fixture schema is constrained during preparation. */
    public function activate(TenantAdoptionPlan $plan): void {}

    /** Resolve the fixture owner's effective connection. */
    private function connection(): Connection
    {
        return (new TestMetafieldOwner)->getConnection();
    }
}
