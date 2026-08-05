<?php

declare(strict_types=1);

use Nvl\Metafields\Enums\MetafieldTypeEnum;
use Nvl\Metafields\Support\MetafieldValidationRuleCompiler;

it('accepts integer uuid ulid and string-style reference identifiers', function (mixed $identifier): void {
    expect(MetafieldValidationRuleCompiler::passes(
        MetafieldTypeEnum::Reference,
        true,
        null,
        $identifier,
    ))->toBeTrue();
})->with([
    'integer' => 42,
    'uuid' => '019f9dc9-598f-722d-8279-2af9a4368984',
    'ulid' => '01J9Z6JXZFDJ3C92W9W9M3H8YG',
    'string' => 'external-product-42',
]);

it('rejects compound reference identifiers', function (): void {
    expect(MetafieldValidationRuleCompiler::passes(
        MetafieldTypeEnum::Reference,
        true,
        null,
        ['id' => 42],
    ))->toBeFalse();
});
