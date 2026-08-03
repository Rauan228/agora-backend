<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\Admin\SupplierResource;
use App\Models\City;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $city = trim((string) $request->query('city', ''));
        $status = (string) $request->query('status', '');

        $suppliers = Supplier::query()
            ->with('cities')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('commercial_name', 'like', "%{$search}%")
                        ->orWhere('legal_name', 'like', "%{$search}%")
                        ->orWhere('inn', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($city !== '', fn ($q) => $q->whereHas('cities', fn ($sub) => $sub->where('name', $city)))
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->latest()
            ->paginate(min(max((int) $request->integer('per_page', 15), 1), 100));

        return SupplierResource::collection($suppliers);
    }

    public function show(Supplier $supplier)
    {
        return new SupplierResource($supplier->load('cities'));
    }

    public function store(StoreSupplierRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        $data['is_active'] = $request->boolean('is_active', true);

        $supplier = Supplier::create($data);
        $this->syncCities($supplier, $this->citiesFromRequest($request));

        return (new SupplierResource($supplier->load('cities')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($supplier->logo_path) {
                Storage::disk('public')->delete($supplier->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('logos', 'public');
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $supplier->update($data);
        $this->syncCities($supplier, $this->citiesFromRequest($request));

        return new SupplierResource($supplier->fresh()->load('cities'));
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->logo_path) {
            Storage::disk('public')->delete($supplier->logo_path);
        }

        $supplier->delete();

        return response()->json(null, 204);
    }

    /** @return list<string> */
    private function citiesFromRequest(Request $request): array
    {
        $cities = $request->input('cities', []);
        if (is_string($cities)) {
            $cities = array_filter(array_map('trim', explode(',', $cities)));
        }

        return is_array($cities) ? $cities : [];
    }

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
