<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\TidyTags\Http\Controllers\DashboardController;
use JustinHoltWeb\TidyTags\Http\Controllers\DuplicatesController;
use JustinHoltWeb\TidyTags\Http\Controllers\ItemsController;
use JustinHoltWeb\TidyTags\Http\Controllers\SourceController;
use JustinHoltWeb\TidyTags\Plugin;

// The CP group already applies `auth`, `can:accessCp` and the cpTrigger prefix. Craft only hides
// the nav item from users without plugin access, so the routes check it themselves.

Route::middleware('can:'.Plugin::PERMISSION_ACCESS)->group(function () {
    Route::get('tidytags', DashboardController::class)->name('tidytags');
    Route::get('tidytags/duplicates', DuplicatesController::class)->name('tidytags.duplicates');
    Route::get('tidytags/section/{sectionId}', SourceController::class)->whereNumber('sectionId')->name('tidytags.section');

    // Read-only lookups: the Duplicates screen's usages and the editor's "did you mean?".
    Route::get('tidytags/tags/usages', [ItemsController::class, 'usages']);
    Route::get('tidytags/tags/check-duplicate', [ItemsController::class, 'checkDuplicate']);

    Route::middleware('can:'.Plugin::PERMISSION_MANAGE_TAGS)->group(function () {
        Route::post('tidytags/tags/rename', [ItemsController::class, 'rename']);
        Route::post('tidytags/tags/swap', [ItemsController::class, 'swap']);
    });

    Route::middleware('can:'.Plugin::PERMISSION_DELETE_TAGS)->group(function () {
        Route::post('tidytags/tags/delete', [ItemsController::class, 'delete']);

        Route::middleware('can:'.Plugin::PERMISSION_MANAGE_TAGS)->group(function () {
            Route::post('tidytags/tags/merge', [ItemsController::class, 'merge']);
            Route::get('tidytags/tags/url-report', [ItemsController::class, 'urlReport']);
        });
    });
});
