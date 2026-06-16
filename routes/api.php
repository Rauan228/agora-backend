<?php

use App\Http\Controllers\Api\SupplierController;
use Illuminate\Support\Facades\Route;

// Публичное API для фронта (read-only)
Route::get('/suppliers', [SupplierController::class, 'index']);
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
