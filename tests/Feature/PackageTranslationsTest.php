<?php

declare(strict_types=1);

test('every literal package translation key has a standalone English value', function (): void {
    app()->setLocale('en');

    $keys = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../src', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents)) {
            continue;
        }

        preg_match_all('/\btrans\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $key) {
            if (str_starts_with($key, 'metafields::') && ! str_contains($key, '{$') && ! str_ends_with($key, '.')) {
                $keys[$key] = true;
            }
        }
    }

    foreach (array_keys($keys) as $key) {
        expect(trans($key))->not->toBe($key);
    }
});

test('bundled English and Bulgarian catalogs have key parity', function (): void {
    $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;

            if (is_array($value)) {
                $keys = [...$keys, ...$flatten($value, $path)];
            }
        }

        return $keys;
    };

    foreach ([
        'metafields/validation.php',
        'metafield-definitions/validation.php',
        'owner-metafields/validation.php',
    ] as $relativePath) {
        $english = require __DIR__.'/../../lang/en/'.$relativePath;
        $bulgarian = require __DIR__.'/../../lang/bg/'.$relativePath;

        expect($flatten($bulgarian))->toBe($flatten($english));
    }
});
