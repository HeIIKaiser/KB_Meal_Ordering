<?php

use App\Http\Controllers\Employee\LoginController;
use App\Http\Controllers\Employee\MonthController;
use App\Http\Controllers\Employee\PinController;
use App\Livewire\OrderScreen;
use Illuminate\Support\Facades\Route;

/*
| Employee app (guard "employee"). Staff use the Filament panel at /admin.
*/

Route::middleware('guest:employee')->group(function () {
    Route::get('/prihlasenie', [LoginController::class, 'create'])->name('login');
    Route::post('/prihlasenie', [LoginController::class, 'store']);
});

Route::middleware(['auth:employee', 'employee.active'])->group(function () {
    Route::livewire('/', OrderScreen::class)->name('home');
    Route::get('/moj-mesiac', MonthController::class)->name('month');
    Route::view('/ucet', 'employee.account')->name('account');
    Route::put('/ucet/pin', [PinController::class, 'update'])->name('pin.update');
    Route::post('/odhlasenie', [LoginController::class, 'destroy'])->name('logout');
});
