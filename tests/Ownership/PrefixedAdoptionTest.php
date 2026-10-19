<?php

declare(strict_types=1);
use Nvl\Metafields\Tests\Fixtures\MetafieldsPrefixedOwnershipTestCase;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Ownership\Services\OwnershipRunner;

uses(MetafieldsPrefixedOwnershipTestCase::class);
it('prepares backfills and activates prefixed metafields storage without duplicating constraints', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        expect(OwnershipCompany::findOrFail(7)->getConnection()->getTablePrefix())->toBe('review_');
    });
});
