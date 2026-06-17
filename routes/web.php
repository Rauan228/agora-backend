<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\SupplierController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.login');
});

// Отдача загруженных файлов (логотипы) напрямую из storage/app/public.
// Префикс /files (не /storage — там встроенный роут Laravel для local-диска).
// Не зависит от симлинка public/storage — надёжно на эфемерных хостингах (Railway).
Route::get('files/{path}', function (string $path) {
    $disk = Illuminate\Support\Facades\Storage::disk('public');
    abort_unless($disk->exists($path), 404);

    return response()->file($disk->path($path));
})->where('path', '.*')->name('files.show');

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
