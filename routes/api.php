<?php

use App\Http\Controllers\Api\Admin\AiSessionController as AdminAiSessionController;
use App\Http\Controllers\Api\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\MetaController as AdminMetaController;
use App\Http\Controllers\Api\Admin\OfferController as AdminOfferController;
use App\Http\Controllers\Api\Admin\SupplierController as AdminSupplierController;
use App\Http\Controllers\Api\AiSessionController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API (витрина Next.js — https://github.com/paulzverev/agora)
|--------------------------------------------------------------------------
*/
Route::get('/categories', [CategoryController::class, 'index']);

Route::get('/suppliers', [SupplierController::class, 'index']);
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);

Route::get('/offers', [OfferController::class, 'index']);
Route::get('/offers/{offer}', [OfferController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Public AI matching (catalog-grounded chat)
|--------------------------------------------------------------------------
*/
Route::prefix('ai')->group(function () {
    Route::get('/catalog', [AiSessionController::class, 'catalog']);
    Route::post('/sessions', [AiSessionController::class, 'store']);
    Route::get('/sessions/{session}', [AiSessionController::class, 'show']);
    Route::post('/sessions/{session}/messages', [AiSessionController::class, 'message']);
    Route::post('/sessions/{session}/stream', [AiSessionController::class, 'stream']);
    Route::post('/sessions/{session}/refine', [AiSessionController::class, 'refine']);
});



/*
|--------------------------------------------------------------------------
| Admin API (React SPA на Vercel) — Sanctum Bearer token
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/dashboard', [DashboardController::class, 'show']);
        Route::get('/ai/ledger', [DashboardController::class, 'sessions']);

        // Справочники / схемы форм
        Route::get('/meta/dictionaries', [AdminMetaController::class, 'dictionaries']);
        Route::get('/meta/categories', [AdminMetaController::class, 'categories']);
        Route::get('/meta/cities', [AdminMetaController::class, 'cities']);
        Route::get('/meta/suppliers', [AdminMetaController::class, 'suppliersOptions']);

        // Поставщики
        Route::get('/suppliers', [AdminSupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [AdminSupplierController::class, 'show']);
        Route::post('/suppliers', [AdminSupplierController::class, 'store']);
        Route::post('/suppliers/{supplier}', [AdminSupplierController::class, 'update']); // multipart-friendly
        Route::put('/suppliers/{supplier}', [AdminSupplierController::class, 'update']);
        Route::patch('/suppliers/{supplier}', [AdminSupplierController::class, 'update']);
        Route::delete('/suppliers/{supplier}', [AdminSupplierController::class, 'destroy']);

        // Офферы (SKU)
        Route::get('/offers', [AdminOfferController::class, 'index']);
        Route::get('/offers/{offer}', [AdminOfferController::class, 'show']);
        Route::post('/offers', [AdminOfferController::class, 'store']);
        Route::post('/offers/{offer}', [AdminOfferController::class, 'update']); // multipart-friendly
        Route::put('/offers/{offer}', [AdminOfferController::class, 'update']);
        Route::patch('/offers/{offer}', [AdminOfferController::class, 'update']);
        Route::delete('/offers/{offer}', [AdminOfferController::class, 'destroy']);

        // AI matching + cost meter (admin only — not for public storefront)
        Route::prefix('ai')->group(function () {
            Route::get('/catalog', [AdminAiSessionController::class, 'catalog']);
            Route::get('/sessions', [AdminAiSessionController::class, 'index']);
            Route::post('/sessions', [AdminAiSessionController::class, 'store']);
            Route::get('/sessions/{session}', [AdminAiSessionController::class, 'show']);
            Route::post('/sessions/{session}/messages', [AdminAiSessionController::class, 'message']);
            Route::post('/sessions/{session}/stream', [AdminAiSessionController::class, 'stream']);
            Route::post('/sessions/{session}/refine', [AdminAiSessionController::class, 'refine']);
        });
    });
});
