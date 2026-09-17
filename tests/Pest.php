<?php

declare(strict_types=1);

use Nvl\Metafields\Tests\MetafieldCatalogTenancyTestCase;
use Nvl\Metafields\Tests\MetafieldTenancyTestCase;
use Nvl\Metafields\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(MetafieldTenancyTestCase::class)->in('Tenancy/Feature');
uses(MetafieldCatalogTenancyTestCase::class)->in('Tenancy/Catalog');
