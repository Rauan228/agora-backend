<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\City;
use App\Models\Supplier;

/**
 * Справочники и схемы форм для React-админки.
 */
class MetaController extends Controller
{
    public function dictionaries()
    {
        return response()->json([
            'currencies' => config('agora.currencies', []),
            'dictionaries' => config('agora.dictionaries', []),
        ]);
    }

    public function categories()
    {
        $schemaBySlug = collect(config('agora.categories', []))->keyBy('slug');

        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Category $cat) use ($schemaBySlug) {
                $schema = $schemaBySlug->get($cat->slug, []);

                return [
                    'id' => $cat->id,
                    'slug' => $cat->slug,
                    'name' => $cat->name,
                    'priority' => $cat->priority,
                    'sort_order' => $cat->sort_order,
                    'fields' => $schema['fields'] ?? ($cat->fieldSchema() ?? []),
                ];
            });

        return response()->json(['data' => $categories]);
    }

    public function cities()
    {
        return response()->json([
            'data' => City::orderBy('name')->pluck('name'),
        ]);
    }

    public function suppliersOptions()
    {
        $items = Supplier::query()
            ->orderBy('commercial_name')
            ->get(['id', 'commercial_name', 'is_active']);

        return response()->json(['data' => $items]);
    }
}
