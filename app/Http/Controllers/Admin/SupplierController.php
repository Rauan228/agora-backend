<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\City;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupplierController extends Controller
{
    /** Список поставщиков с поиском по названию/ИНН. */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $suppliers = Supplier::query()
            ->with('cities')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('commercial_name', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('inn', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.suppliers.index', compact('suppliers', 'search'));
    }

    /** Форма создания. */
    public function create()
    {
        return view('admin.suppliers.create', [
            'supplier' => new Supplier(),
            'selectedCities' => [],
        ]);
    }

    /** Сохранение нового поставщика. */
    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $supplier = Supplier::create($data);
        $this->syncCities($supplier, $request->input('cities', []));

        return redirect()
            ->route('admin.suppliers.index')
            ->with('status', "Поставщик «{$supplier->commercial_name}» создан.");
    }

    /** Форма редактирования. */
    public function edit(Supplier $supplier)
    {
        $supplier->load('cities');

        return view('admin.suppliers.edit', [
            'supplier' => $supplier,
            'selectedCities' => $supplier->cities->pluck('name')->all(),
        ]);
    }

    /** Обновление поставщика. */
    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            // удаляем старый логотип, если был
            if ($supplier->logo_path) {
                Storage::disk('public')->delete($supplier->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $supplier->update($data);
        $this->syncCities($supplier, $request->input('cities', []));

        return redirect()
            ->route('admin.suppliers.index')
            ->with('status', "Поставщик «{$supplier->commercial_name}» обновлён.");
    }

    /** Удаление поставщика. */
    public function destroy(Supplier $supplier)
    {
        if ($supplier->logo_path) {
            Storage::disk('public')->delete($supplier->logo_path);
        }

        $name = $supplier->commercial_name;
        $supplier->delete();

        return redirect()
            ->route('admin.suppliers.index')
            ->with('status', "Поставщик «{$name}» удалён.");
    }

    /**
     * Привязывает города к поставщику, создавая отсутствующие в справочнике.
     * Названия городов приходят строками; пустые игнорируются.
     */
    private function syncCities(Supplier $supplier, array $cityNames): void
    {
        $cityIds = collect($cityNames)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn ($name) => City::firstOrCreate(['name' => $name])->id)
            ->all();

        $supplier->cities()->sync($cityIds);
    }
}
