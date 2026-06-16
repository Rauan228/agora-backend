<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.login');
});

Route::prefix('admin')->name('admin.')->group(function () {
    // Аутентификация
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.attempt');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Защищённая часть админки
    Route::middleware('auth')->group(function () {
        Route::redirect('/', '/admin/suppliers');
        Route::resource('suppliers', SupplierController::class)->except('show');
    });
});
