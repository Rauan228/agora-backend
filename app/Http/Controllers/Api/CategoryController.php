<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;

/** Публичный список категорий для витрины / фильтров. */
class CategoryController extends Controller
{
    public function index()
    {
        $items = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'priority', 'sort_order']);

        return response()->json(['data' => $items]);
    }
}
