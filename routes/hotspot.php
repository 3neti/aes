<?php

use App\Http\Controllers\HotspotPortalController;
use Illuminate\Support\Facades\Route;

Route::get('/hotspot', [HotspotPortalController::class, 'show'])->name('hotspot.show');
Route::post('/hotspot/connect', [HotspotPortalController::class, 'connect'])
    ->middleware('throttle:30,1')
    ->name('hotspot.connect');

foreach ((array) config('hotspot.probes', []) as $host => $paths) {
    foreach ((array) $paths as $path => $kind) {
        Route::domain((string) $host)
            ->get((string) $path, [HotspotPortalController::class, 'probe'])
            ->defaults('kind', (string) $kind)
            ->name('hotspot.probe.'.md5($host.$path));
    }
}
