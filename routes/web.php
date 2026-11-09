<?php

use App\Http\Controllers\Admin\AdminFilesController;
use App\Http\Controllers\Admin\KitchenExportController;
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

/*
| Staff helpers outside the Filament layout (auth + role checked in the controller).
*/
Route::get('/admin/listky', [AdminFilesController::class, 'slips'])->name('admin.slips');
Route::get('/admin/sablona/{type}', [AdminFilesController::class, 'template'])->name('admin.template');

Route::prefix('/admin/kuchyna')->name('kitchen.')->group(function () {
    Route::get('/tlac', [KitchenExportController::class, 'print'])->name('print');
    Route::get('/pdf', [KitchenExportController::class, 'pdf'])->name('pdf');
    Route::get('/xlsx', [KitchenExportController::class, 'xlsx'])->name('xlsx');
});
