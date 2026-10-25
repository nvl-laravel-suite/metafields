<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Nvl\Metafields\Actions\GrantMetafieldDefinitionToTenantAction;
use Nvl\Metafields\Actions\ImportPlatformMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\ArchiveMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\CreateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\DeleteMetafieldDefinitionAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\ListMetafieldDefinitionsAction;
use Nvl\Metafields\Actions\MetafieldDefinitions\UpdateMetafieldDefinitionAction;
use Nvl\Metafields\Actions\Metafields\DeleteOwnerMetafieldAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\ListAuthorizedOwnersMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\ListOwnerMetafieldsAction;
use Nvl\Metafields\Actions\Metafields\SetMetafieldAction;
use Nvl\Metafields\Actions\Metafields\SyncOwnerMetafieldsAction;
use Nvl\Metafields\Actions\RevokeMetafieldDefinitionTenantGrantAction;
use Nvl\Metafields\Contracts\ArchiveMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\CreateMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\DeleteOwnerMetafieldContract;
use Nvl\Metafields\Contracts\GrantMetafieldDefinitionToTenantContract;
use Nvl\Metafields\Contracts\ImportPlatformMetafieldDefinitionContract;
use Nvl\Metafields\Contracts\ListAuthorizedOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\ListAuthorizedOwnersMetafieldsContract;
use Nvl\Metafields\Contracts\ListMetafieldDefinitionsContract;
use Nvl\Metafields\Contracts\ListOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\RevokeMetafieldDefinitionTenantGrantContract;
use Nvl\Metafields\Contracts\SetMetafieldContract;
use Nvl\Metafields\Contracts\SyncOwnerMetafieldsContract;
use Nvl\Metafields\Contracts\UpdateMetafieldDefinitionContract;
use Nvl\Metafields\Providers\MetafieldsServiceProvider;
use Nvl\Metafields\Tests\TestCase;

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses(TestCase::class);
}

/** @return list<array{class-string, class-string}> */
function nvlConsumerBindingsForMetafields(): array
{
    return [
        [ArchiveMetafieldDefinitionContract::class, ArchiveMetafieldDefinitionAction::class],
        [CreateMetafieldDefinitionContract::class, CreateMetafieldDefinitionAction::class],
        [DeleteMetafieldDefinitionContract::class, DeleteMetafieldDefinitionAction::class],
        [DeleteOwnerMetafieldContract::class, DeleteOwnerMetafieldAction::class],
        [GrantMetafieldDefinitionToTenantContract::class, GrantMetafieldDefinitionToTenantAction::class],
        [ImportPlatformMetafieldDefinitionContract::class, ImportPlatformMetafieldDefinitionAction::class],
        [ListAuthorizedOwnerMetafieldsContract::class, ListAuthorizedOwnerMetafieldsAction::class],
        [ListAuthorizedOwnersMetafieldsContract::class, ListAuthorizedOwnersMetafieldsAction::class],
        [ListMetafieldDefinitionsContract::class, ListMetafieldDefinitionsAction::class],
        [ListOwnerMetafieldsContract::class, ListOwnerMetafieldsAction::class],
        [RevokeMetafieldDefinitionTenantGrantContract::class, RevokeMetafieldDefinitionTenantGrantAction::class],
        [SetMetafieldContract::class, SetMetafieldAction::class],
        [SyncOwnerMetafieldsContract::class, SyncOwnerMetafieldsAction::class],
        [UpdateMetafieldDefinitionContract::class, UpdateMetafieldDefinitionAction::class],
    ];
}

test('published workflow contracts retain native signatures attributes and generic documentation', function (): void {
    $genericDocumentation = static function (string|false $documentation): array {
        if ($documentation === false) {
            return [];
        }
        preg_match_all('/@param\s+([^\r\n]+?)\s+(\$[A-Za-z_][A-Za-z0-9_]*)\b/', $documentation, $parameters, PREG_SET_ORDER);
        $result = [];
        foreach ($parameters as $parameter) {
            $type = preg_replace('/\s+/', '', $parameter[1]);
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@param'.$parameter[2]] = $type;
            }
        }
        if (preg_match('/@return\s+([^\r\n]+)/', $documentation, $return) === 1) {
            $type = '';
            $depth = 0;
            foreach (str_split($return[1]) as $character) {
                if (preg_match('/\s/', $character) === 1 && $depth === 0) {
                    break;
                }
                if (str_contains('<{([', $character)) {
                    $depth++;
                } elseif (str_contains('>})]', $character)) {
                    $depth--;
                }
                if (preg_match('/\s/', $character) !== 1) {
                    $type .= $character;
                }
            }
            if (str_contains($type, '<') || str_contains($type, '{') || str_contains($type, '[]')) {
                $result['@return'] = $type;
            }
        }

        return $result;
    };

    foreach (nvlConsumerBindingsForMetafields() as [$contract, $implementation]) {
        $interface = new ReflectionClass($contract);
        $concrete = new ReflectionClass($implementation);
        expect($interface->isInterface())->toBeTrue()
            ->and($concrete->implementsInterface($contract))->toBeTrue();
        foreach ($interface->getMethods() as $method) {
            $native = $concrete->getMethod($method->getName());
            $return = (string) $method->getReturnType();

            $publishedTypes = $genericDocumentation($method->getDocComment());
            foreach ($genericDocumentation($native->getDocComment()) as $tag => $type) {
                expect($publishedTypes[$tag] ?? null)->toBe($type);
            }

            expect($native->isPublic())->toBeTrue()
                ->and($native->isStatic())->toBeFalse()
                ->and(count($method->getParameters()))->toBe(count($native->getParameters()));
            if ($return !== 'self') {
                expect((string) $native->getReturnType())->toBe($return);
            } else {
                $nativeReturn = (string) $native->getReturnType();
                expect(is_a(in_array($nativeReturn, ['self', 'static'], true) ? $native->getDeclaringClass()->getName() : $nativeReturn, $contract, true))->toBeTrue();
            }
            foreach ($method->getParameters() as $position => $parameter) {
                $actual = $native->getParameters()[$position];
                expect($actual->getName())->toBe($parameter->getName())
                    ->and((string) $actual->getType())->toBe((string) $parameter->getType())
                    ->and($actual->isVariadic())->toBe($parameter->isVariadic())
                    ->and($actual->isPassedByReference())->toBe($parameter->isPassedByReference())
                    ->and($actual->isDefaultValueAvailable())->toBe($parameter->isDefaultValueAvailable())
                    ->and(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $actual->getAttributes()))
                    ->toBe(array_map(static fn (ReflectionAttribute $attribute): array => [$attribute->getName(), $attribute->getArguments()], $parameter->getAttributes()));
                if ($parameter->isDefaultValueAvailable()) {
                    expect($actual->getDefaultValue())->toEqual($parameter->getDefaultValue());
                }
            }
        }
    }
});

test('native provider defaults resolve each workflow while preserving late host substitutes', function (): void {
    foreach (nvlConsumerBindingsForMetafields() as [$contract, $implementation]) {
        expect($this->app->bound($contract))->toBeTrue()
            ->and($this->app->make($contract))->toBeInstanceOf($implementation);
        $host = Mockery::mock($contract);
        $this->app->instance($contract, $host);
        expect($this->app->make($contract))->toBe($host);
    }
});

test('provider registration preserves early interface bindings in a second native application', function (): void {
    $consumer = new Application($this->app->basePath());
    $consumer->instance('config', new Repository($this->app->make('config')->all()));
    $consumer->instance('env', 'testing');
    $consumer->register(FilesystemServiceProvider::class);
    $hosts = [];
    foreach (nvlConsumerBindingsForMetafields() as [$contract]) {
        $hosts[$contract] = Mockery::mock($contract);
        $consumer->instance($contract, $hosts[$contract]);
    }
    try {
        $consumer->register(MetafieldsServiceProvider::class);
        foreach ($hosts as $contract => $host) {
            expect($consumer->make($contract))->toBe($host);
        }
    } finally {
        Container::setInstance($this->app);
        $consumer->flush();
    }
});
