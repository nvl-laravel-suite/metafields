<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Nvl\Metafields\Contracts\MetafieldDiagnosticAccess;
use Nvl\Metafields\Enums\MetafieldAbility;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Exceptions\InvalidMetafieldMutationException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldDoctor;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\CommittedMetafieldsTestCase;
use Nvl\Metafields\Tests\Fixtures\BatchSoftDeletingMetafieldOwner;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Enums\SystemActor;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(CommittedMetafieldsTestCase::class);

beforeEach(function (): void {
    foreach (['create', 'update', 'write', 'read', 'delete'] as $ability) {
        Gate::define('metafields.'.$ability, static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    }
    Gate::define('metafields.owner', static fn (SystemActor $actor, string $ability): bool => true);
    config([
        'nvl-metafields.authorization.owner_ability' => 'metafields.owner',
        'nvl-translatable.locales' => ['en', 'bg'],
        'nvl-metafields.owners' => [
            'products' => ['model' => TestMetafieldOwner::class, 'label' => 'Products', 'sections' => ['general']],
            'softproducts' => ['model' => BatchSoftDeletingMetafieldOwner::class, 'label' => 'Soft products', 'sections' => ['general']],
        ],
    ]);
});

/** @return array{owner:TestMetafieldOwner,definition:MetafieldDefinition,value:Metafield} */
function metafieldLifecycleRecords(): array
{
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'lifecycle', 'type' => 'string', 'isTranslatable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Lifecycle']],
    ], SystemActor::Background);
    $owner = TestMetafieldOwner::query()->create(['name' => 'Owner']);
    $value = app(MetafieldValues::class)->set($owner, 'product.lifecycle', 'Private value', SystemActor::Background, 'en');

    return compact('owner', 'definition', 'value');
}

/** Grant only the dedicated read-only diagnostic seam in this isolated host. */
function allowMetafieldLifecycleDiagnostics(): void
{
    app()->instance(MetafieldDiagnosticAccess::class, new class implements MetafieldDiagnosticAccess
    {
        public function allows(SystemActor $actor): bool
        {
            return $actor === SystemActor::Background;
        }
    });
}

it('requires an explicit purge capability and a real caller transaction before lifecycle mutation', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    expect(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toThrow(AuthorizationException::class);
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    expect($owner->getConnection()->transactionLevel())->toBe(0)
        ->and(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toThrow(InvalidMetafieldMutationException::class)
        ->and(Metafield::query()->count())->toBe(1)
        ->and(MetafieldTranslation::query()->count())->toBe(1);
});

it('purges active and trashed localized values atomically before permanent owner deletion', function (): void {
    ['owner' => $owner, 'definition' => $definition, 'value' => $value] = metafieldLifecycleRecords();
    $other = TestMetafieldOwner::query()->create(['name' => 'Other owner']);
    app(MetafieldValues::class)->set($other, 'product.lifecycle', 'Other value', SystemActor::Background, 'en');
    $value->delete();
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    Event::fake([MetafieldsSynced::class]);

    $owner->getConnection()->transaction(function () use ($owner): void {
        expect(app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toBe(1);
        expect($owner->delete())->toBeTrue();
        Event::assertNotDispatched(MetafieldsSynced::class);
    });

    Event::assertDispatchedTimes(MetafieldsSynced::class, 1);
    expect(Metafield::withTrashed()->count())->toBe(1)
        ->and(MetafieldTranslation::query()->count())->toBe(1)
        ->and(MetafieldDefinition::query()->whereKey($definition->id)->exists())->toBeTrue()
        ->and(TestMetafieldOwner::query()->whereKey($other->getKey())->exists())->toBeTrue();
});

it('rolls lifecycle purge and permanent owner deletion back with the host transaction', function (): void {
    ['owner' => $owner, 'value' => $value] = metafieldLifecycleRecords();
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    Event::fake([MetafieldsSynced::class]);

    expect(fn () => $owner->getConnection()->transaction(function () use ($owner): void {
        app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background);
        $owner->delete();
        throw new RuntimeException('Host deletion cancelled');
    }))->toThrow(RuntimeException::class, 'cancelled');

    expect(TestMetafieldOwner::query()->whereKey($owner->getKey())->exists())->toBeTrue()
        ->and(Metafield::query()->whereKey($value->id)->exists())->toBeTrue()
        ->and(MetafieldTranslation::query()->where('metafield_id', $value->id)->count())->toBe(1);
    Event::assertNotDispatched(MetafieldsSynced::class);
});

it('retains per-value delete authorization during lifecycle purge', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    Gate::define('metafields.owner', static fn (SystemActor $actor, string $ability): bool => $ability !== MetafieldAbility::DeleteOwnerValue->value);

    expect(fn () => $owner->getConnection()->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))->toThrow(AuthorizationException::class)
        ->and(Metafield::query()->count())->toBe(1)
        ->and(MetafieldTranslation::query()->count())->toBe(1);
});

it('rejects an owner connection alias even when it borrows the same PDO', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    $source = $owner->getConnection();
    config(['database.connections.metafields_alias' => $source->getConfig()]);
    DB::connection('metafields_alias')->setPdo($source->getPdo());
    $owner->setConnection('metafields_alias');
    try {
        expect(fn () => $source->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))->toThrow(OwnershipViolation::class)
            ->and(Metafield::query()->count())->toBe(1);
    } finally {
        DB::purge('metafields_alias');
    }
});

it('can purge a soft-deleted owner immediately before its permanent deletion', function (): void {
    app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'soft', 'type' => 'string',
        'assignment' => ['ownerType' => 'softproducts', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Soft owner']],
    ], SystemActor::Background);
    $owner = BatchSoftDeletingMetafieldOwner::query()->create(['name' => 'Soft owner']);
    app(MetafieldValues::class)->set($owner, 'product.soft', 'Retained while soft deleted', SystemActor::Background);
    $owner->delete();
    expect(Metafield::query()->count())->toBe(1);
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);

    $owner->getConnection()->transaction(function () use ($owner): void {
        expect(app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toBe(1)
            ->and($owner->forceDelete())->toBeTrue();
    });

    expect(Metafield::withTrashed()->count())->toBe(0)
        ->and(BatchSoftDeletingMetafieldOwner::withTrashed()->count())->toBe(0);
});

it('bounds lifecycle hydration when one owner has more than two batches of values', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    $definitions = [];
    $values = [];
    for ($i = 0; $i < 204; $i++) {
        $id = (string) Str::uuid();
        $definitions[] = ['id' => $id, 'namespace' => 'batch', 'key' => 'field_'.$i, 'handle' => 'batch.field_'.$i, 'active_handle' => 'batch.field_'.$i, 'type' => 'string'];
        $values[] = ['id' => (string) Str::uuid(), 'definition_id' => $id, 'metafieldable_type' => $owner->getMorphClass(), 'metafieldable_id' => (string) $owner->getKey(), 'value' => 'Retained value'];
    }
    MetafieldDefinition::query()->insert($definitions);
    Metafield::query()->insert($values);
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    $reads = [];
    $connection = $owner->getConnection();
    $table = $connection->getQueryGrammar()->wrapTable((new Metafield)->getTable());
    $connection->listen(function (QueryExecuted $query) use (&$reads, $table): void {
        if (str_starts_with(strtolower($query->sql), 'select ') && str_contains($query->sql, 'from '.$table) && str_contains($query->sql, 'limit 100')) {
            $reads[] = $query->sql;
        }
    });
    $connection->transaction(function () use ($owner): void {
        expect(app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toBe(205);
        $owner->delete();
    });

    expect($reads)->toHaveCount(4)->and(Metafield::withTrashed()->count())->toBe(0)
        ->and(MetafieldDefinition::query()->count())->toBe(205);
});

it('keeps owner-reference diagnostics behind an explicit read capability', function (): void {
    metafieldLifecycleRecords();
    $check = collect(app(MetafieldDoctor::class)->inspect())->firstWhere('key', 'data.owner_references');

    expect($check)->not->toBeNull()->and($check['passed'])->toBeFalse()
        ->and($check['message'])->toContain('MetafieldDiagnosticAccess', 'skipped', 'no references inspected');
});

it('reports unresolved registered references within scopes without misclassifying soft deletion', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    $owner->delete();
    app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'softdiagnostic', 'type' => 'string',
        'assignment' => ['ownerType' => 'softproducts', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Soft diagnostic']],
    ], SystemActor::Background);
    $soft = BatchSoftDeletingMetafieldOwner::query()->create(['name' => 'Retained owner']);
    app(MetafieldValues::class)->set($soft, 'product.softdiagnostic', 'Soft private value', SystemActor::Background);
    $soft->delete();
    allowMetafieldLifecycleDiagnostics();

    $check = collect(app(MetafieldDoctor::class)->inspect())->firstWhere('key', 'data.owner_references');

    expect($check)->not->toBeNull()->and($check['passed'])->toBeFalse()
        ->and($check['message'])->toContain('1 unresolved', 'within host scopes')
        ->and($check['message'])->not->toContain('Private value')->not->toContain('Soft private value');
});

it('limits diagnostics to a metadata-only sample without asserting global orphan absence', function (): void {
    ['owner' => $owner, 'definition' => $definition] = metafieldLifecycleRecords();
    $rows = [];
    for ($i = 0; $i < 101; $i++) {
        $rows[] = ['id' => (string) Str::uuid(), 'definition_id' => $definition->id, 'metafieldable_type' => $owner->getMorphClass(), 'metafieldable_id' => (string) (10_000 + $i), 'value' => 'Never diagnose this private payload'];
    }
    Metafield::query()->insert($rows);
    allowMetafieldLifecycleDiagnostics();
    $selects = [];
    $connection = (new Metafield)->getConnection();
    $table = $connection->getQueryGrammar()->wrapTable((new Metafield)->getTable());
    $connection->listen(function (QueryExecuted $query) use (&$selects, $table): void {
        if (str_starts_with(strtolower($query->sql), 'select ') && str_contains($query->sql, 'from '.$table)) {
            $selects[] = $query->sql;
        }
    });

    $check = collect(app(MetafieldDoctor::class)->inspect())->firstWhere('key', 'data.owner_references');

    expect($check['passed'])->toBeFalse()->and($check['message'])->toContain('Sampled 100', 'more rows remain', 'within host scopes')
        ->and($check['message'])->not->toContain('private payload')
        ->and($selects)->toHaveCount(1)->and($selects[0])->toContain('limit 101')
        ->and($selects[0])->not->toContain($connection->getQueryGrammar()->wrap('value'));
});

it('inspects schema and diagnostic references on the actual writer despite a lagging read PDO', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    allowMetafieldLifecycleDiagnostics();
    $connection = $owner->getConnection();
    $read = $connection->getReadPdo();
    $modified = $connection->hasModifiedRecords();
    $connection->setRecordModificationState(false);
    $connection->setReadPdo(new PDO('sqlite::memory:'));
    try {
        $checks = collect(app(MetafieldDoctor::class)->inspect());
        expect($checks->filter(static fn (array $check): bool => str_starts_with($check['key'], 'schema.table.'))->every(static fn (array $check): bool => $check['passed']))->toBeTrue()
            ->and($checks->firstWhere('key', 'data.owner_references')['message'])->toContain('Sampled 1', '0 unresolved');
    } finally {
        $connection->setReadPdo($read);
        $connection->setRecordModificationState($modified);
    }
});

it('skips ungranted diagnostics safely when a host global Gate hook requires an authorizable actor', function (): void {
    $hooks = 0;
    Gate::before(static function (Authorizable $actor) use (&$hooks): ?bool {
        $hooks++;

        return null;
    });

    $check = collect(app(MetafieldDoctor::class)->inspect())->firstWhere('key', 'data.owner_references');

    expect($check['passed'])->toBeFalse()->and($check['message'])->toContain('skipped', 'no references inspected')
        ->and($hooks)->toBe(0);
});

it('retains explicit background purge grants alongside authorizable global Gate hooks', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    Gate::before(static fn (Authorizable $actor): ?bool => null);
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => $actor === SystemActor::Background);

    $owner->getConnection()->transaction(function () use ($owner): void {
        expect(app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background))->toBe(1);
        $owner->delete();
    });

    expect(Metafield::withTrashed()->count())->toBe(0);
});

it('fences the captured purge source after every opaque authorization before issuing queries', function (string $phase): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    $source = $owner->getConnection();
    $pdo = $source->getPdo();
    $foreign = $this->foreignLifecycleSource($owner);
    $foreignPdo = $foreign->getPdo();
    $foreignQueries = 0;
    $source->beforeExecuting(static function () use ($source, $foreignPdo, &$foreignQueries): void {
        if ($source->getRawPdo() === $foreignPdo) {
            $foreignQueries++;
        }
    });
    $purgeGrants = 0;
    Gate::define('metafields.purge', static function (SystemActor $actor) use ($phase, $source, $foreignPdo, &$purgeGrants): bool {
        if (++$purgeGrants === 2 && $phase === 'purge') {
            $source->setPdo($foreignPdo);
        }

        return true;
    });
    Gate::define('metafields.owner', static function (SystemActor $actor, string $ability) use ($phase, $source, $foreignPdo): bool {
        if ($ability === $phase) {
            $source->setPdo($foreignPdo);
        }

        return true;
    });
    Event::fake([MetafieldsSynced::class]);

    try {
        expect(fn () => $source->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))
            ->toThrow(OwnershipViolation::class);
    } finally {
        $source->setPdo($pdo);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        DB::purge('metafields_lifecycle_foreign');
    }

    expect($foreignQueries)->toBe(0)->and(Metafield::withTrashed()->count())->toBe(1)
        ->and(MetafieldTranslation::query()->count())->toBe(1);
    Event::assertNotDispatched(MetafieldsSynced::class);
})->with(['purge', MetafieldAbility::MutateOwner->value, MetafieldAbility::PurgeOwnerValues->value, MetafieldAbility::DeleteOwnerValue->value]);

it('fences lifecycle delete SQL after opaque model deleting listeners', function (string $phase): void {
    ['owner' => $owner, 'value' => $value] = metafieldLifecycleRecords();
    $source = $owner->getConnection();
    $pdo = $source->getPdo();
    $foreign = $this->foreignLifecycleSource($owner);
    $foreignPdo = $foreign->getPdo();
    $foreign->table($value->getTable())->insert($value->getRawOriginal());
    $locale = MetafieldTranslation::query()->where('metafield_id', $value->id)->sole();
    $foreign->table($locale->getTable())->insert($locale->getRawOriginal());
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    if (str_starts_with($phase, 'locale')) {
        MetafieldTranslation::deleting(static function (MetafieldTranslation $record) use ($phase, $source, $foreignPdo): void {
            if ($phase === 'locale_alias') {
                $record->setConnection('metafields_lifecycle_foreign');
            } else {
                $source->setPdo($foreignPdo);
            }
        });
    } elseif ($phase === 'force') {
        Metafield::forceDeleting(static function () use ($source, $foreignPdo): void {
            $source->setPdo($foreignPdo);
        });
    } else {
        Metafield::deleting(static function (Metafield $record) use ($phase, $source, $foreignPdo): void {
            if ($phase === 'value_alias') {
                $record->setConnection('metafields_lifecycle_foreign');
            } else {
                $source->setPdo($foreignPdo);
            }
        });
    }
    $foreignQueries = 0;
    $source->beforeExecuting(static function () use ($source, $foreignPdo, &$foreignQueries): void {
        if ($source->getRawPdo() === $foreignPdo) {
            $foreignQueries++;
        }
    });
    $foreign->beforeExecuting(static function () use (&$foreignQueries): void {
        $foreignQueries++;
        throw new RuntimeException('Lifecycle deletion reached a foreign source.');
    });
    Event::fake([MetafieldsSynced::class]);
    try {
        expect(fn () => $source->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))
            ->toThrow(OwnershipViolation::class);
    } finally {
        $source->setPdo($pdo);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    expect($foreignQueries)->toBe(0)->and($foreignPdo->query('select count(*) from '.$foreign->getQueryGrammar()->wrapTable($value->getTable()))->fetchColumn())->toBe(1)
        ->and($foreignPdo->query('select count(*) from '.$foreign->getQueryGrammar()->wrapTable($locale->getTable()))->fetchColumn())->toBe(1)
        ->and(Metafield::withTrashed()->count())->toBe(1)
        ->and(MetafieldTranslation::query()->count())->toBe(1);
    Event::assertNotDispatched(MetafieldsSynced::class);
    DB::purge('metafields_lifecycle_foreign');
})->with(['locale', 'value', 'force', 'locale_alias', 'value_alias']);

it('rejects a disconnected lifecycle source without reconnecting or ending the host PDO transaction', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    $source = $owner->getConnection();
    $pdo = $source->getPdo();
    $grants = 0;
    Gate::define('metafields.purge', static function (SystemActor $actor) use ($source, &$grants): bool {
        if (++$grants === 2) {
            $source->disconnect();
        }

        return true;
    });

    try {
        expect(fn () => $source->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))
            ->toThrow(OwnershipViolation::class);
        expect($source->getRawPdo())->toBeNull()->and($pdo->inTransaction())->toBeTrue();
    } finally {
        $source->setPdo($pdo);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    expect(Metafield::withTrashed()->count())->toBe(1);
});

it('rechecks the host source after the nested transaction completion callback', function (): void {
    ['owner' => $owner] = metafieldLifecycleRecords();
    $source = $owner->getConnection();
    $pdo = $source->getPdo();
    $foreignPdo = $this->foreignLifecycleSource($owner)->getPdo();
    Gate::define('metafields.purge', static fn (SystemActor $actor): bool => true);
    Event::fake([MetafieldsSynced::class]);
    Event::listen(TransactionCommitted::class, static function (TransactionCommitted $event) use ($source, $foreignPdo): void {
        if ($event->connection === $source && $source->transactionLevel() === 1) {
            $source->setPdo($foreignPdo);
        }
    });

    try {
        expect(fn () => $source->transaction(fn () => app(MetafieldValues::class)->purgeBeforeOwnerDeletion($owner, SystemActor::Background)))
            ->toThrow(OwnershipViolation::class);
        expect($pdo->inTransaction())->toBeTrue();
        $source->transaction(static fn (): null => null);
        Event::assertNotDispatched(MetafieldsSynced::class);
    } finally {
        $source->setPdo($pdo);
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        DB::purge('metafields_lifecycle_foreign');
    }

    expect(Metafield::withTrashed()->count())->toBe(1)->and(MetafieldTranslation::query()->count())->toBe(1);
});
