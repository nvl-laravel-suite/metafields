<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Services\MetafieldDefinitions;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\CommittedMetafieldsTestCase;
use Nvl\Metafields\Tests\Fixtures\MetafieldCasWorkerProvider;
use Nvl\Metafields\Tests\Fixtures\MetafieldPostgreSqlSource;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldActor;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Symfony\Component\Process\Process;

uses(CommittedMetafieldsTestCase::class);

it('accepts exactly one localized set or restore across independent PostgreSQL processes', function (bool $restore): void {
    if (getenv('NVL_C1_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly isolated committed PostgreSQL package database.');
    }

    $connection = DB::connection();
    $configuration = MetafieldPostgreSqlSource::capture($connection);
    unset($configuration['_nvl_metafield_cas_parent']);
    MetafieldCasWorkerProvider::$source = $configuration;
    $this->app->register(MetafieldCasWorkerProvider::class);
    $actor = TestMetafieldActor::query()->create(['id' => 1, 'name' => 'CAS operator']);
    $owner = TestMetafieldOwner::query()->create(['id' => 1, 'name' => 'Concurrent owner']);
    $definition = app(MetafieldDefinitions::class)->create([
        'namespace' => 'product', 'key' => 'concurrent-caption', 'type' => 'string', 'isTranslatable' => true,
        'assignment' => ['ownerType' => 'products', 'section' => 'general'],
        'translations' => ['en' => ['title' => 'Concurrent caption']],
    ], $actor);
    $initial = app(MetafieldValues::class)->sync($owner, [[
        'definitionId' => $definition->id,
        'translations' => ['en' => 'Untouched English', 'bg' => 'Начална стойност'],
    ]], $actor)->sole();
    $source = MetafieldPostgreSqlSource::capture($owner->getConnection());
    $parent = $source['_nvl_metafield_cas_parent'];
    expect($initial->revision)->toBe(1);
    if ($restore) {
        app(MetafieldValues::class)->sync($owner, [[
            'definitionId' => $definition->id, 'clear' => true, 'expected_revision' => 1,
        ]], $actor);
        expect(Metafield::onlyTrashed()->sole()->id)->toBe($initial->id);
    }

    $directory = sys_get_temp_dir().'/nvl-metafield-cas-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the Metafield CAS barrier directory.');
    }
    $workers = [];

    try {
        foreach (['Градина', 'Тераса'] as $index => $value) {
            $worker = new Process([
                PHP_BINARY,
                __DIR__.'/Fixtures/metafield-cas-worker.php',
                json_encode($source, JSON_THROW_ON_ERROR),
                json_encode([
                    'directory' => $directory, 'index' => $index, 'value' => $value,
                    'actor' => $actor->getKey(), 'owner' => $owner->getKey(),
                    'definition' => $definition->id, 'handle' => $definition->handle,
                    'restore' => $restore,
                ], JSON_THROW_ON_ERROR),
            ], env: ['XDEBUG_MODE' => 'off'], timeout: 25);
            $worker->start();
            $workers[] = $worker;
        }

        $deadline = microtime(true) + 10;
        while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
            foreach ($workers as $worker) {
                $worker->checkTimeout();
                if (! $worker->isRunning()) {
                    throw new RuntimeException('A Metafield CAS worker exited before release: '.$worker->getErrorOutput());
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The Metafield CAS ready barrier timed out.');
            }
            usleep(10000);
            clearstatcache();
        }

        $processIds = [$parent['process_id']];
        $connectionIds = [$parent['connection_id']];
        foreach ($workers as $index => $worker) {
            $ready = json_decode(file_get_contents($directory.'/ready-'.$index), true, flags: JSON_THROW_ON_ERROR);
            expect($ready['database'])->toBe($parent['database'])
                ->and($ready['process_id'])->toBe($worker->getPid())
                ->and($ready['server_version'])->toBe($parent['server_version']);
            $processIds[] = $ready['process_id'];
            $connectionIds[] = $ready['connection_id'];
        }
        expect(array_unique($processIds))->toHaveCount(3)
            ->and(array_unique($connectionIds))->toHaveCount(3);
        file_put_contents($directory.'/release', 'release');

        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        $outcomes = collect($outcomes);
        expect($outcomes->pluck('status')->sort()->values()->all())->toBe(['stale', 'written']);
        $winner = $outcomes->firstWhere('status', 'written');
        $loser = $outcomes->firstWhere('status', 'stale');
        $stored = Metafield::query()->with('translations')->sole();

        expect($stored->id)->toBe($initial->id)
            ->and($stored->definition_id)->toBe($definition->id)
            ->and($stored->metafieldable_type)->toBe($owner->getMorphClass())
            ->and($stored->metafieldable_id)->toBe((string) $owner->getKey())
            ->and($stored->revision)->toBe(2)
            ->and($stored->translations->pluck('value', 'locale')->sortKeys()->all())->toBe([
                'bg' => $winner['value'], ...($restore ? [] : ['en' => 'Untouched English']),
            ])
            ->and($stored->getValue('bg'))->toBe($winner['value'])
            ->and($stored->getValue('en'))->toBe($restore ? null : 'Untouched English')
            ->and($winner['revision'])->toBe(2)
            ->and($winner['metafield_id'])->toBe($initial->id)
            ->and($winner['set_events'])->toBe([[
                'metafield_id' => $initial->id, 'definition_id' => $definition->id,
                'owner_type' => $owner->getMorphClass(), 'owner_id' => (string) $owner->getKey(),
                'revision' => 2, 'transaction_level' => 0,
            ]])
            ->and($winner['sync_events'])->toBe([[$initial->id]])
            ->and($loser['exception'])->toBe($restore ? ValidationException::class : StaleMetafieldVersionException::class)
            ->and($loser['set_events'])->toBe([])
            ->and($loser['sync_events'])->toBe([])
            ->and($owner->getConnection()->transactionLevel())->toBe(0);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
})->with(['update' => false, 'restore' => true]);
