<?php

declare(strict_types=1);

use Nvl\Metafields\Tests\TestCase;

$featureTests = glob(__DIR__.'/Feature/*Test.php') ?: [];
uses(TestCase::class)->in(...array_values($featureTests));
