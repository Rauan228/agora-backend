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

    /**
     * Опции поставщиков для селекта в форме оффера.
     * ?q= — поиск по названию / ИНН (удобно при 25+ поставщиках).
     * Отдаём logo_url для превью логотипа в админке.
     */
    public function suppliersOptions()
    {
        $q = trim((string) request()->query('q', ''));
        $limit = min(max((int) request()->integer('limit', 50), 1), 200);

        $items = Supplier::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('commercial_name', 'like', "%{$q}%")
                        ->orWhere('legal_name', 'like', "%{$q}%")
                        ->orWhere('inn', 'like', "%{$q}%");
                });
            })
            ->orderBy('commercial_name')
            ->limit($limit)
            ->get(['id', 'commercial_name', 'legal_name', 'inn', 'logo_path', 'is_active'])
            ->map(fn (Supplier $s) => [
                'id' => $s->id,
                'commercial_name' => $s->commercial_name,
                'legal_name' => $s->legal_name,
                'inn' => $s->inn,
                'logo_url' => $s->logo_url,
                'is_active' => $s->is_active,
            ]);

        return response()->json(['data' => $items]);
    }
}

