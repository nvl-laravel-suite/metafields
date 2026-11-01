<?php

declare(strict_types=1);

use Nvl\Metafields\Tests\TestCase;

$featureTests = array_filter(glob(__DIR__.'/Feature/*Test.php') ?: [], static fn (string $file): bool => basename($file) !== 'MetafieldOwnerLifecycleTest.php');
uses(TestCase::class)->in(...array_values($featureTests));
