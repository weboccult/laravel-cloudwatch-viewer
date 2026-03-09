<?php

use Weboccult\CloudWatchViewer\Http\Controllers\LogViewerController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix'     => config('cloudwatch-viewer.route_prefix'),
    'middleware' => config('cloudwatch-viewer.middleware'),
], function () {
    Route::get('/', [LogViewerController::class, 'index'])
        ->name('cloudwatch-viewer.index');

    Route::get('/fetch', [LogViewerController::class, 'fetch'])
        ->name('cloudwatch-viewer.fetch');

    Route::get('/live', [LogViewerController::class, 'live'])
        ->name('cloudwatch-viewer.live');
});
