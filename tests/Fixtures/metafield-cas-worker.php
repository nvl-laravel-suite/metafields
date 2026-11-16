<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Nvl\Metafields\Events\MetafieldSet;
use Nvl\Metafields\Events\MetafieldsSynced;
use Nvl\Metafields\Exceptions\StaleMetafieldVersionException;
use Nvl\Metafields\Models\Metafield;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Services\MetafieldValues;
use Nvl\Metafields\Tests\Fixtures\MetafieldCasWorkerProvider;
use Nvl\Metafields\Tests\Fixtures\MetafieldPostgreSqlSource;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldActor;
use Nvl\Metafields\Tests\Fixtures\TestMetafieldOwner;
use Nvl\Support\Providers\LocaleServiceProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Translatable\Providers\TranslatableServiceProvider;
use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__, 5).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$options = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_string($source['database'] ?? null) || preg_match('/^nvl_metafields_test_[a-f0-9]{8}_ci$/D', $source['database']) !== 1
    || ! is_array($source['_nvl_metafield_cas_parent'] ?? null)
    || ! is_array($options) || ! is_string($options['directory'] ?? null) || ! is_dir($options['directory'])
    || preg_match('/^nvl-metafield-cas-[a-f0-9]{16}$/D', basename($options['directory'])) !== 1
    || ! in_array($options['index'] ?? null, [0, 1], true)
    || ! is_int($options['actor'] ?? null) || ! is_int($options['owner'] ?? null)
    || ! is_string($options['definition'] ?? null) || ! is_string($options['handle'] ?? null)
    || ! is_string($options['value'] ?? null) || ! is_bool($options['restore'] ?? null)) {
    throw new RuntimeException('Metafield CAS workers require an explicit disposable PostgreSQL fixture and barriers.');
}

$configuration = [];
foreach ($source as $key => $value) {
    if (! is_string($key)) {
        throw new RuntimeException('Metafield CAS worker source configuration keys must be strings.');
    }
    $configuration[$key] = $value;
}
MetafieldCasWorkerProvider::$source = $configuration;
$app = Application::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [
        MetafieldCasWorkerProvider::class, LocaleServiceProvider::class, SupportServiceProvider::class,
        TranslatableServiceProvider::class, MetafieldsServiceProvider::class,
    ],
]]);
$app->make(Kernel::class)->bootstrap();
$connection = $app->make('db')->connection();
$identity = MetafieldPostgreSqlSource::inspect($connection);
$actor = TestMetafieldActor::query()->findOrFail($options['actor']);
$owner = TestMetafieldOwner::query()->findOrFail($options['owner']);
$restore = $options['restore'];
$initial = Metafield::query()->withTrashed()->with('translations')->where('definition_id', $options['definition'])->sole();
if ($initial->revision !== 1 || $initial->trashed() !== $restore
    || ($restore ? $initial->translations->isNotEmpty() : $initial->getValue('en') !== 'Untouched English' || $initial->getValue('bg') !== 'Начална стойност')) {
    throw new RuntimeException('Both CAS workers must observe the committed revision-one locale graph before release.');
}
$setEvents = [];
$syncEvents = [];
Event::listen(MetafieldSet::class, static function (MetafieldSet $event) use (&$setEvents, $connection): void {
    $setEvents[] = [
        'metafield_id' => $event->metafieldId, 'definition_id' => $event->definitionId,
        'owner_type' => $event->ownerType, 'owner_id' => (string) $event->ownerId,
        'revision' => $event->revision, 'transaction_level' => $connection->transactionLevel(),
    ];
});
Event::listen(MetafieldsSynced::class, static function (MetafieldsSynced $event) use (&$syncEvents): void {
    $syncEvents[] = $event->metafieldIds;
});
$ready = $options['directory'].'/ready-'.$options['index'];
file_put_contents($ready.'.pending', json_encode($identity, JSON_THROW_ON_ERROR));
rename($ready.'.pending', $ready);
$deadline = microtime(true) + 10;
while (! is_file($options['directory'].'/release')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('The Metafield CAS release barrier timed out.');
    }
    usleep(10000);
    clearstatcache();
}

try {
    $written = $app->make(MetafieldValues::class)->set($owner, $options['handle'], $options['value'], $actor, 'bg', expectedRevision: $restore ? null : 1);
    $outcome = ['status' => 'written', 'metafield_id' => $written->id, 'revision' => $written->revision, 'value' => $options['value']];
} catch (StaleMetafieldVersionException $exception) {
    $outcome = ['status' => 'stale', 'exception' => $exception::class];
} catch (ValidationException $exception) {
    if (! $restore || ! isset($exception->errors()['items.0.expected_revision'])) {
        throw $exception;
    }
    $outcome = ['status' => 'stale', 'exception' => $exception::class];
}
MetafieldPostgreSqlSource::inspect($connection);
fwrite(STDOUT, json_encode([...$outcome, 'set_events' => $setEvents, 'sync_events' => $syncEvents], JSON_THROW_ON_ERROR));
