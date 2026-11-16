<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Models\MetafieldTranslation;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use PDO;
use RuntimeException;

/** Proves host transaction boundaries on disposable native or in-memory storage. */
abstract class CommittedMetafieldsTestCase extends TestCase
{
    /** Prepare a separate, disposable physical source for hostile host-callback regressions. */
    protected function foreignLifecycleSource(TestMetafieldOwner $owner): Connection
    {
        $source = $owner->getConnection();
        $configuration = $source->getConfig();
        if (! is_array($configuration)) {
            throw new RuntimeException('The lifecycle fixture requires an explicit database configuration.');
        }
        $database = $source->getDriverName() === 'sqlite' ? ':memory:' : getenv('NVL_OWNERSHIP_TEST_REMOTE_DATABASE');
        if (! is_string($database) || ($database !== ':memory:'
            && (preg_match('/^nvl_own_test_metafields_[a-f0-9]{8}_remote$/D', $database) !== 1 || $database === $source->getDatabaseName()))) {
            throw new RuntimeException('The lifecycle fixture requires its dedicated foreign scratch database.');
        }
        $configuration['database'] = $database;
        unset($configuration['read'], $configuration['write'], $configuration['url']);
        config(['database.connections.metafields_lifecycle_foreign' => $configuration]);
        DB::purge('metafields_lifecycle_foreign');
        $foreign = DB::connection('metafields_lifecycle_foreign');
        if ($foreign->getPdo() === $source->getPdo()
            || ($database !== ':memory:' && $foreign->scalar($foreign->getDriverName() === 'pgsql'
                ? 'select current_database()' : 'select database()', [], false) !== $database)) {
            throw new RuntimeException('The lifecycle fixture must use its distinct actual scratch database.');
        }
        $schema = $foreign->getSchemaBuilder();
        $schema->dropAllTables();
        $schema->create($owner->getTable(), static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('category')->nullable();
            $table->timestamps();
        });
        $schema->create((new Metafield)->getTable(), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('definition_id');
            $table->string('metafieldable_type');
            $table->string('metafieldable_id');
            $table->uuid('referenced_id')->nullable();
            $table->text('value')->nullable();
            $table->integer('revision')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create((new MetafieldTranslation)->getTable(), static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('metafield_id');
            $table->string('locale');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $foreign->table($owner->getTable())->insert($owner->getRawOriginal());

        return $foreign;
    }

    public function refreshDatabase(): void
    {
        $connection = app('db')->connection();
        if (! ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:')
            && preg_match('/^nvl_metafields_test_[a-f0-9]{8}_ci$/D', $connection->getDatabaseName()) !== 1) {
            throw new RuntimeException('Committed Metafields tests require their dedicated package database.');
        }
        $driver = getenv('NVL_C1_TEST_DRIVER');
        if ($driver === 'pgsql') {
            $this->assertSame($driver, $connection->getDriverName());
            $this->assertSame('pgsql', $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        }
        $migration = $this->artisan('migrate:fresh', ['--force' => true]);
        if ($migration instanceof PendingCommand) {
            $migration->assertSuccessful();
        } else {
            $this->assertSame(0, $migration);
        }
        $this->beforeApplicationDestroyed(static function (): void {
            RefreshDatabaseState::$migrated = false;
        });
    }
}
