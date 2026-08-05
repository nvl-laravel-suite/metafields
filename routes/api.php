<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\Metafields\Http\Controllers\Api\MetafieldsApiController;

$middleware = array_values(array_filter(
    (array) config('metafields.routes.management_middleware', ['auth']),
    static fn (mixed $value): bool => is_string($value) && $value !== '',
));

Route::middleware($middleware)
    ->prefix('metafields')
    ->name('nvl.metafields.management.')
    ->group(function (): void {
        Route::get('/owners', [MetafieldsApiController::class, 'owners'])->name('owners.index');
        Route::get('/owners/{ownerType}/{ownerId}', [MetafieldsApiController::class, 'ownerFields'])
            ->name('owners.fields');
        Route::put('/owners/{ownerType}/{ownerId}', [MetafieldsApiController::class, 'syncOwnerFields'])
            ->name('owners.fields.sync');
        Route::delete('/owners/{ownerType}/{ownerId}/{definition}', [MetafieldsApiController::class, 'destroyOwnerField'])
            ->whereUuid('definition')
            ->name('owners.fields.destroy');

        Route::get('/definitions', [MetafieldsApiController::class, 'definitions'])->name('definitions.index');
        Route::post('/definitions', [MetafieldsApiController::class, 'storeDefinition'])->name('definitions.store');
        Route::get('/definitions/{definition}', [MetafieldsApiController::class, 'showDefinition'])
            ->whereUuid('definition')
            ->name('definitions.show');
        Route::put('/definitions/{definition}', [MetafieldsApiController::class, 'updateDefinition'])
            ->whereUuid('definition')
            ->name('definitions.update');
        Route::patch('/definitions/{definition}/archive', [MetafieldsApiController::class, 'archiveDefinition'])
            ->whereUuid('definition')
            ->name('definitions.archive');
        Route::delete('/definitions/{definition}', [MetafieldsApiController::class, 'destroyDefinition'])
            ->whereUuid('definition')
            ->name('definitions.destroy');
    });
