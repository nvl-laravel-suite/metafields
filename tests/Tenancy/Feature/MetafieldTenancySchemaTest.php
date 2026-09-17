<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Metafields\Definitions\Tables\MetafieldsTables;
use Nvl\Metafields\Tests\Fixtures\MetafieldTenancyScenario;
use Nvl\Tenancy\Services\TenantResourceRegistry;

it('declares and constrains the entire metafield tenant graph', function (): void {
    MetafieldTenancyScenario::install();

    expect(array_keys(app(TenantResourceRegistry::class)->all()))
        ->toContain(
            'metafields.definitions',
            'metafields.definition-assignments',
            'metafields.definition-translations',
            'metafields.values',
            'metafields.value-translations',
            'metafields.catalog-grants',
        );
    foreach ([
        MetafieldsTables::Definitions,
        MetafieldsTables::DefinitionAssignments,
        MetafieldsTables::DefinitionsI18n,
        MetafieldsTables::Metafields,
        MetafieldsTables::I18n,
        MetafieldsTables::TenantGrants,
    ] as $table) {
        expect(Schema::hasColumn($table, 'tenant_id'))->toBeTrue();
    }
    expect(Schema::hasIndex(
        MetafieldsTables::Metafields,
        ['tenant_id', 'metafieldable_type', 'metafieldable_id', 'definition_id'],
        'unique',
    ))->toBeTrue();
});

it('keeps the optional tenant schema absent while tenancy remains disabled', function (): void {
    expect(config('tenancy.enabled'))->toBeTrue();
});
