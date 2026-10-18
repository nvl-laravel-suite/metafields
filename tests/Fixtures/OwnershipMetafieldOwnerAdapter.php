<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/** Activates empty host-owned subject storage through actual partition constraints. */
final readonly class OwnershipMetafieldOwnerAdapter implements OwnershipAdoptionAdapter
{
    public function __construct(private OwnershipSchema $schema, private OwnershipAdoptionStore $mappings) {}

    public function resources(): array
    {
        return ['fixture.metafield-owners'];
    }

    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->prepare('fixture.metafield-owners');
    }

    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $model = new OwnershipMetafieldOwner;
        $rows = $model->getConnection()->table($model->getTable())->when($cursor !== null, static fn ($q) => $q->where('id', '>', $cursor))->orderBy('id')->limit($limit)->get();
        $lastId = null;
        foreach ($rows as $row) {
            if (! is_string($row->id) || (! is_int($row->company_id) && ! is_string($row->company_id))) {
                throw new \RuntimeException('Host mappings require their native scalar facts.');
            }
            $lastId = $row->id;
            $owner = $this->mappings->forRecord($plan, 'fixture.metafield-owners', $row->id)->requireOwner();
            if ($owner->key !== (string) $row->company_id) {
                throw new \RuntimeException('Host owner mapping cannot relabel a native company.');
            }$model->getConnection()->table($model->getTable())->where('id', $row->id)->update(['owner_scope_key' => $owner->scopeKey()]);
        }

        return new OwnershipBackfillResult($rows->count() === $limit ? $lastId : null, $rows->count());
    }

    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->schema->verify('fixture.metafield-owners');

        return new OwnershipVerification([]);
    }

    public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->schema->constrain('fixture.metafield-owners');
    }
}
