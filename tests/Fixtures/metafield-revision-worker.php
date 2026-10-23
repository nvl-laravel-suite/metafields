<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Data\SyncOwnerMetafieldsPayload;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Tests\Fixtures\MetafieldRevisionWorkerProvider;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use Spatie\LaravelData\LaravelDataServiceProvider;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$options = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($options) || ! is_string($options['directory'] ?? null)
    || ! str_starts_with(basename($options['directory']), 'metafields-race-')
    || ! is_file($options['directory'].'/database.sqlite')
    || ! is_int($options['index'] ?? null)
    || ! is_string($options['mode'] ?? null) || ! in_array($options['mode'], ['single', 'patch', 'replace'], true)
    || ! is_string($options['handle'] ?? null) || ! is_string($options['value'] ?? null)
    || ! is_string($options['definition'] ?? null)
    || (! is_int($options['owner'] ?? null) && ! is_string($options['owner'] ?? null))) {
    throw new RuntimeException('Metafield workers require an explicit committed SQLite race fixture.');
}
MetafieldRevisionWorkerProvider::$configuration = [
    'database.default' => 'metafields_race',
    'database.connections.metafields_race' => [
        'driver' => 'sqlite', 'database' => $options['directory'].'/database.sqlite', 'prefix' => '',
        'foreign_key_constraints' => true, 'busy_timeout' => 5000, 'journal_mode' => 'WAL', 'transaction_mode' => 'IMMEDIATE',
    ],
    'nvl-core.connection' => null, 'nvl-metafields.connection' => null,
    'nvl-tenancy.enabled' => false, 'nvl-ownership.enabled' => false,
    'nvl-translatable.locales' => ['en', 'bg'], 'nvl-translatable.fallback_locales' => ['en'],
    'nvl-metafields.owners' => ['products' => ['model' => TestMetafieldOwner::class, 'sections' => ['general'], 'runtime_status' => 'live']],
];
$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [MetafieldRevisionWorkerProvider::class, LocaleServiceProvider::class, LaravelDataServiceProvider::class, SupportServiceProvider::class, DataServiceProvider::class, TranslatableServiceProvider::class, MetafieldsServiceProvider::class],
]]);
$app->make(Kernel::class)->bootstrap();
Date::setTestNow('2026-10-23 12:00:00');
$events = [];
$syncEvents = 0;
Event::listen(MetafieldSet::class, function (MetafieldSet $event) use (&$events): void {
    $events[] = $event->revision;
});
Event::listen(MetafieldsSynced::class, function () use (&$syncEvents): void {
    $syncEvents++;
});
touch($options['directory'].'/ready-'.$options['index']);
$deadline = microtime(true) + 20;
while (! is_file($options['directory'].'/start')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The Metafield worker barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}
try {
    $owner = TestMetafieldOwner::query()->findOrFail($options['owner']);
    if ($options['mode'] === 'single') {
        $result = $app->make(SetMetafieldAction::class)->execute($owner, $options['handle'], $options['value'], 'en', 1);
    } else {
        $result = $app->make(SyncOwnerMetafieldsAction::class)->execute($owner, SyncOwnerMetafieldsPayload::from(['items' => [[
            'definitionId' => $options['definition'], 'translations' => ['en' => $options['value']],
            'translationMode' => $options['mode'], 'expectedRevision' => 1,
        ]]]))->sole();
    }
    $outcome = ['status' => 'written', 'revision' => $result->revision, 'value' => $options['value'], 'events' => $events, 'syncEvents' => $syncEvents];
} catch (StaleMetafieldVersionException) {
    $outcome = ['status' => 'stale', 'events' => $events, 'syncEvents' => $syncEvents];
}
fwrite(STDOUT, json_encode($outcome, JSON_THROW_ON_ERROR));
