<?php

declare(strict_types=1);
use Nvl\Metafields\Models\MetafieldDefinitionAssignment;
use Nvl\Metafields\Models\MetafieldDefinitionTranslation;
use Nvl\Metafields\Services\MetafieldModelRegistry;
use Nvl\Metafields\Tests\Fixtures\OwnershipActor;
use Nvl\Metafields\Tests\Fixtures\OwnershipCompany;
use Nvl\Metafields\Tests\Fixtures\OwnershipMetafieldScenario;
use Nvl\Metafields\Tests\OwnershipSourceTestCase;
use Nvl\Ownership\Services\OwnershipRunner;

uses(OwnershipSourceTestCase::class);
it('rolls the complete definition workflow back on its actual source connection', function (): void {
    app(OwnershipRunner::class)->run(OwnershipCompany::findOrFail(7), OwnershipActor::firstOrFail(), function (): void {
        MetafieldDefinitionAssignment::creating(static function (): void {
            throw new RuntimeException('veto-source-assignment');
        });
        expect(fn () => OwnershipMetafieldScenario::definition())->toThrow(RuntimeException::class, 'veto-source-assignment');
        expect(MetafieldModelRegistry::query()->count())->toBe(0)->and(MetafieldDefinitionTranslation::query()->count())->toBe(0);
        $checks = app(\Nvl\Metafields\Services\MetafieldDoctor::class)->inspect();
        $schemaErrors = array_filter($checks, static fn ($check) => ! $check->passed && str_starts_with($check->key, 'schema.'));
        expect(array_values($schemaErrors))->toBe([]);
    });
});
