<?php

use App\Http\Controllers\Admin\AdminFilesController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\KitchenExportController;
use App\Http\Controllers\Employee\LoginController;
use App\Http\Controllers\Employee\MonthController;
use App\Http\Controllers\Employee\PinController;
use App\Http\Controllers\ManifestController;
use App\Livewire\OrderScreen;
use Illuminate\Support\Facades\Route;

/*
| Employee app (guard "employee"). Staff use the Filament panel at /admin.
*/

Route::get('/manifest.webmanifest', ManifestController::class)->name('manifest');

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
| Staff helpers outside the Filament layout. Every route here MUST carry a
| staff:<roles> middleware (enforced by tests/Feature/Security/SecurityTest.php).
*/
Route::get('/admin/listky', [AdminFilesController::class, 'slips'])->middleware('staff:admin')->name('admin.slips');
Route::get('/admin/zalohy/stiahnut/{file}', [AdminFilesController::class, 'backup'])->middleware('staff:admin')->name('admin.backup');
Route::get('/admin/sablona/{type}', [AdminFilesController::class, 'template'])->middleware('staff:admin,kitchen')->name('admin.template');

Route::prefix('/admin/kuchyna')->middleware('staff:kitchen,admin,payroll')->name('kitchen.')->group(function () {
    Route::get('/tlac', [KitchenExportController::class, 'print'])->name('print');
    Route::get('/pdf', [KitchenExportController::class, 'pdf'])->name('pdf');
    Route::get('/xlsx', [KitchenExportController::class, 'xlsx'])->name('xlsx');
});

Route::prefix('/admin/jedalny-listok')->middleware('staff:kitchen,admin,payroll')->name('menu.week.')->group(function () {
    Route::get('/tlac', [KitchenExportController::class, 'weekPrint'])->name('print');
    Route::get('/pdf', [KitchenExportController::class, 'weekPdf'])->name('pdf');
});

Route::prefix('/admin/exporty')->middleware('staff:payroll,admin')->name('exports.')->group(function () {
    Route::get('/mesiac', [ExportController::class, 'monthly'])->name('monthly');
    Route::get('/zoznam', [ExportController::class, 'list'])->name('list');
});
