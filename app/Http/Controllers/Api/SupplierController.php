<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    /**
     * Список активных поставщиков для фронта.
     * Параметры запроса:
     *   q        — поиск по названию (коммерческому/юридическому)
     *   city     — фильтр по городу отгрузки (точное совпадение названия)
     *   per_page — размер страницы (1..100, по умолчанию 20)
     *   page     — номер страницы (стандартная пагинация Laravel)
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100)); // защита от чрезмерных запросов

        $suppliers = Supplier::query()
            ->where('is_active', true)
            ->with('cities')
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where(function ($sub) use ($q) {
                    $sub->where('commercial_name', 'like', "%{$q}%")
                        ->orWhere('legal_name', 'like', "%{$q}%");
                });
            })
            ->when($request->filled('city'), function ($query) use ($request) {
                $city = $request->string('city');
                $query->whereHas('cities', fn ($sub) => $sub->where('name', $city));
            })
            ->orderBy('commercial_name')
            ->paginate($perPage);

        return SupplierResource::collection($suppliers);
    }

    /** Один поставщик. */
    public function show(Supplier $supplier)
    {
        abort_unless($supplier->is_active, 404);

        return new SupplierResource($supplier->load('cities'));
    }
}
