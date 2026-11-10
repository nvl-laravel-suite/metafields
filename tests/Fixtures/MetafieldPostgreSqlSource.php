<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

/** Validates the disposable committed source and independent sessions used by the CAS fixture. */
final class MetafieldPostgreSqlSource
{
    /** @return array<string, mixed> */
    public static function capture(Connection $connection): array
    {
        $configuration = $connection->getConfig();
        if (! is_array($configuration)) {
            throw new RuntimeException('Metafield CAS workers require an explicit PostgreSQL source.');
        }
        $source = [];
        foreach ($configuration as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Metafield CAS source configuration keys must be strings.');
            }
            $source[$key] = $value;
        }
        $source['_nvl_metafield_cas_parent'] = self::inspect($connection);

        return $source;
    }

    /** @return array{database: string, server_version: string, process_id: int, connection_id: int} */
    public static function inspect(Connection $connection): array
    {
        $database = $connection->getConfig('database');
        if ($connection->getDriverName() !== 'pgsql'
            || ! is_string($database) || preg_match('/^nvl_metafields_test_[a-f0-9]{8}_ci$/D', $database) !== 1
            || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Metafield CAS requires its dedicated committed PostgreSQL package database.');
        }
        $pdo = $connection->getPdo();
        $physical = $connection->selectOne('select current_database() as database, pg_backend_pid() as connection_id', [], false);
        if (! is_object($physical)) {
            throw new RuntimeException('Metafield CAS requires a physical PostgreSQL source identity.');
        }
        $columns = get_object_vars($physical);
        $processId = getmypid();
        $connectionId = filter_var($columns['connection_id'] ?? null, FILTER_VALIDATE_INT);
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || ($columns['database'] ?? null) !== $database
            || $pdo->inTransaction() || ! is_string($version) || $version === ''
            || ! is_int($processId) || $processId < 1 || ! is_int($connectionId) || $connectionId < 1) {
            throw new RuntimeException('Metafield CAS selected an unexpected physical PostgreSQL source.');
        }
        $identity = ['database' => $database, 'server_version' => $version, 'process_id' => $processId, 'connection_id' => $connectionId];
        $parent = $connection->getConfig('_nvl_metafield_cas_parent');
        if ($parent !== null && (! is_array($parent)
            || $database !== ($parent['database'] ?? null) || $version !== ($parent['server_version'] ?? null)
            || $processId === ($parent['process_id'] ?? null) || $connectionId === ($parent['connection_id'] ?? null))) {
            throw new RuntimeException('Metafield CAS workers require independent processes and PostgreSQL connections to the same source.');
        }

        return $identity;
    }
}
