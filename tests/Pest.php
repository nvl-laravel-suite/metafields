<?php

declare(strict_types=1);

use Nvl\Metafields\Tests\Fixtures\LegacyMetafieldValueTestCase;
use Nvl\Metafields\Tests\MetafieldCatalogTenancyTestCase;
use Nvl\Metafields\Tests\MetafieldTenancyTestCase;
use Nvl\Metafields\Tests\TestCase;

$legacyOwnerReadTest = __DIR__.'/Feature/LegacyOwnerReadIntegrityTest.php';
uses(TestCase::class)->in(...array_values(array_filter(glob(__DIR__.'/Feature/*Test.php') ?: [], static fn (string $path): bool => $path !== $legacyOwnerReadTest)));
uses(LegacyMetafieldValueTestCase::class)->in($legacyOwnerReadTest);
uses(MetafieldTenancyTestCase::class)->in('Tenancy/Feature');
uses(MetafieldCatalogTenancyTestCase::class)->in('Tenancy/Catalog');
