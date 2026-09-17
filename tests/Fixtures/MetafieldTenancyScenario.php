<?php

declare(strict_types=1);

namespace Nvl\Metafields\Tests\Fixtures;

use Closure;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Data\CreateMetafieldDefinitionPayload;
use Nvl\Metafields\Models\MetafieldDefinition;
use Nvl\Tenancy\Services\TenantAdoptionCoordinator;
use Nvl\Tenancy\Services\TenantBoundary;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Tenancy\Services\TenantRunner;
use Nvl\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Tenancy\ValueObjects\TenantId;

/** Installs the complete Metafield graph through the real adoption coordinator. */
final readonly class MetafieldTenancyScenario
{
    public const string A = '00000000-0000-4000-8000-00000000000a';

    public const string B = '00000000-0000-4000-8000-00000000000b';

    /** Adopt Metafields plus its canonical owner fixture. */
    public static function install(bool $catalogCopies = false): self
    {
        expect(config('tenancy.enabled'))->toBeTrue()
            ->and(config('tenancy.sharing.metafields'))->toBe($catalogCopies ? 'copy' : 'none');
        app(TenantResourceRegistry::class)->get('test.metafield-owners');

        $operation = new PlatformOperation('fixture.adoption', 'test', 'fixture');
        $coordinator = app(TenantAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['resource-fixture-owners', 'metafields'], [], $operation);
        $done = false;
        for ($batch = 0; $batch < 20 && ! $done; $batch++) {
            $done = $coordinator->backfill($plan, 100, $operation);
        }
        expect($done)->toBeTrue();
        expect($coordinator->verify($plan)->passed())->toBeTrue();
        $coordinator->activate($plan, $operation);
        app(MaintenanceMode::class)->deactivate();

        return new self;
    }

    /** Run one callback under an active tenant. */
    public function run(string $tenant, Closure $callback): mixed
    {
        return app(TenantRunner::class)->run(new TenantId($tenant), $callback);
    }

    /** Run one explicit catalog operation. */
    public function platform(Closure $callback): mixed
    {
        return app(TenantRunner::class)->platform(new PlatformOperation('fixture.catalog', 'test', 'fixture'), $callback);
    }

    /** Create one canonical registered owner. */
    public function owner(string $tenant): TestMetafieldOwner
    {
        return $this->run($tenant, static function (): TestMetafieldOwner {
            $owner = new TestMetafieldOwner(['name' => 'Owner']);
            $owner->forceFill(app(TenantBoundary::class)->attributes('test.metafield-owners'));
            $owner->save();

            return $owner->refresh();
        });
    }

    /** Create an ordinary tenant-owned string definition. */
    public function definition(string $tenant, string $key = 'color'): MetafieldDefinition
    {
        return $this->run($tenant, fn (): MetafieldDefinition => $this->createDefinition($key));
    }

    /** Create one platform catalog definition using the same public writer. */
    public function platformDefinition(string $key = 'color'): MetafieldDefinition
    {
        return $this->platform(fn (): MetafieldDefinition => $this->createDefinition($key));
    }

    /** Build the canonical fixture definition payload. */
    private function createDefinition(string $key): MetafieldDefinition
    {
        return app(CreateMetafieldDefinitionAction::class)->execute(CreateMetafieldDefinitionPayload::from([
            'namespace' => 'catalog',
            'key' => $key,
            'type' => 'string',
            'translations' => ['en' => ['title' => 'Color']],
            'assignment' => ['ownerType' => 'test-owner', 'section' => 'general'],
        ]));
    }
}
