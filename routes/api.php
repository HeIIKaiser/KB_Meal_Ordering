<?php

use App\Http\Controllers\Api\AttendanceSyncController;
use Illuminate\Support\Facades\Route;

/*
| Attendance bridge (docs/ATTENDANCE.md §4): the bridge inside the company network pushes
| data OUT to the app; the app never connects into the network. Bearer token (stored
| hashed), HTTPS in production, rate-limited, optional IP allowlist.
*/
Route::prefix('attendance-sync')
    ->middleware(['attendance.token', 'throttle:attendance-sync'])
    ->name('attendance-sync.')
    ->group(function () {
        Route::post('/', [AttendanceSyncController::class, 'store'])->name('store');
        Route::get('/pending', [AttendanceSyncController::class, 'pending'])->name('pending');
        Route::post('/status', [AttendanceSyncController::class, 'status'])->name('status');
    });
