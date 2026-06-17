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

        // ВРЕМЕННАЯ диагностика хранилища (удалить после починки логотипов)
        Route::get('_diag', function () {
            $publicStorage = public_path('storage');
            $logosDir = storage_path('app/public/logos');
            return response()->json([
                'app_url' => config('app.url'),
                'public_storage_path' => $publicStorage,
                'public_storage_is_link' => is_link($publicStorage),
                'public_storage_exists' => file_exists($publicStorage),
                'storage_public_logos_exists' => is_dir($logosDir),
                'logos_files' => is_dir($logosDir) ? array_values(array_diff(scandir($logosDir), ['.', '..'])) : [],
                'storage_app_public_path' => storage_path('app/public'),
            ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        })->name('diag');
    });
});
