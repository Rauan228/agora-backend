<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OfferResource;
use App\Models\Category;
use App\Models\Offer;
use App\Services\OfferSpecsValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OfferController extends Controller
{
    public function __construct(private OfferSpecsValidator $specsValidator) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $stock = (string) $request->query('stock_status', '');

        $offers = Offer::query()
            ->with(['supplier', 'category'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('offer_title', 'like', "%{$q}%")
                        ->orWhereHas('supplier', fn ($s) => $s->where('commercial_name', 'like', "%{$q}%"));
                });
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('supplier_id'), fn ($query) => $query->where('supplier_id', $request->integer('supplier_id')))
            ->when($stock !== '', fn ($query) => $query->where('stock_status', $stock))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->latest()
            ->paginate(min(max((int) $request->integer('per_page', 15), 1), 100));

        return OfferResource::collection($offers);
    }

    public function show(Offer $offer)
    {
        return new OfferResource($offer->load(['supplier', 'category']));
    }

    public function store(Request $request)
    {
        $data = $this->validateCommon($request);
        $category = Category::findOrFail($data['category_id']);

        $specsInput = $this->specsFromRequest($request);
        $data['specs'] = $this->specsValidator->validate($category, $specsInput);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('offers', 'public');
        }

        $data['is_active'] = $request->boolean('is_active', true);
        $data['pickup_available'] = $request->boolean('pickup_available', false);
        $data['branding_available'] = $request->boolean('branding_available', false);
        $data['price_hidden'] = $request->boolean('price_hidden', false);
        $data['custom_manufacturing'] = $request->boolean('custom_manufacturing', false);
        $data['order_step'] = (int) ($data['order_step'] ?? 1);

        $offer = Offer::create($data);

        return (new OfferResource($offer->load(['supplier', 'category'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Offer $offer)
    {
        $data = $this->validateCommon($request, $offer);
        $category = Category::findOrFail($data['category_id'] ?? $offer->category_id);

        $specsInput = $this->specsFromRequest($request);
        // если specs не прислали — оставляем старые; если прислали (даже пустой) — валидируем
        if ($request->has('specs') || $this->hasSpecKeys($request, $category)) {
            $data['specs'] = $this->specsValidator->validate($category, $specsInput);
        }

        if ($request->hasFile('photo')) {
            if ($offer->photo_path) {
                Storage::disk('public')->delete($offer->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('offers', 'public');
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->has('pickup_available')) {
            $data['pickup_available'] = $request->boolean('pickup_available');
        }
        if ($request->has('branding_available')) {
            $data['branding_available'] = $request->boolean('branding_available');
        }
        if ($request->has('price_hidden')) {
            $data['price_hidden'] = $request->boolean('price_hidden');
        }
        if ($request->has('custom_manufacturing')) {
            $data['custom_manufacturing'] = $request->boolean('custom_manufacturing');
        }

        $offer->update($data);

        return new OfferResource($offer->fresh()->load(['supplier', 'category']));
    }

    public function destroy(Offer $offer)
    {
        if ($offer->photo_path) {
            Storage::disk('public')->delete($offer->photo_path);
        }

        $offer->delete();

        return response()->json(null, 204);
    }

    private function validateCommon(Request $request, ?Offer $offer = null): array
    {
        $regions = config('agora.dictionaries.delivery_region', []);
        $currencies = config('agora.currencies', ['RUB']);

        $validated = $request->validate([
            'supplier_id' => [$offer ? 'sometimes' : 'required', 'integer', 'exists:suppliers,id'],
            'category_id' => [$offer ? 'sometimes' : 'required', 'integer', 'exists:categories,id'],
            'offer_title' => [$offer ? 'sometimes' : 'required', 'string', 'min:5', 'max:180'],
            'sku' => ['nullable', 'string', 'max:100'],
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'price_value' => [$offer ? 'sometimes' : 'required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'price_hidden' => ['sometimes', 'boolean'],
            'currency' => [$offer ? 'sometimes' : 'required', 'string', Rule::in($currencies)],
            'price_basis' => [$offer ? 'sometimes' : 'required', 'string', Rule::in(config('agora.dictionaries.price_basis', []))],
            'moq_value' => [$offer ? 'sometimes' : 'required', 'integer', 'min:1', 'max:1000000'],
            'order_step' => [$offer ? 'sometimes' : 'required', 'integer', 'min:1', 'max:1000000'],
            'stock_status' => [$offer ? 'sometimes' : 'required', 'string', Rule::in(config('agora.dictionaries.stock_status', []))],
            'production_lead_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'delivery_lead_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'delivery_regions' => [$offer ? 'sometimes' : 'required', 'array', 'min:1'],
            'delivery_regions.*' => ['string', Rule::in($regions)],
            'pickup_available' => ['sometimes', 'boolean'],
            'payment_terms' => [$offer ? 'sometimes' : 'required', 'string', Rule::in(config('agora.dictionaries.payment_terms', []))],
            'vat_rate' => [$offer ? 'sometimes' : 'required', 'string', Rule::in(config('agora.dictionaries.vat_rate', []))],
            'branding_available' => ['sometimes', 'boolean'],
            'custom_manufacturing' => ['sometimes', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'description_short' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ], [], [
            'offer_title' => 'название оффера',
            'sku' => 'артикул',
            'supplier_product_code' => 'код товара поставщика',
            'price_value' => 'цена',
            'price_hidden' => 'цена скрыта',
            'price_basis' => 'единица продажи',
            'moq_value' => 'MOQ',
            'order_step' => 'шаг заказа',
            'stock_status' => 'статус наличия',
            'delivery_regions' => 'регион поставки',
            'payment_terms' => 'условия оплаты',
            'vat_rate' => 'НДС',
            'custom_manufacturing' => 'изготовление под заказ',
            'photo' => 'фото',
            'description_short' => 'описание',
        ]);


        // multipart: delivery_regions может прийти JSON-строкой
        if ($request->has('delivery_regions') && is_string($request->input('delivery_regions'))) {
            $decoded = json_decode($request->input('delivery_regions'), true);
            if (is_array($decoded)) {
                $validated['delivery_regions'] = $decoded;
            }
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function specsFromRequest(Request $request): array
    {
        $specs = $request->input('specs', []);

        if (is_string($specs)) {
            $decoded = json_decode($specs, true);
            $specs = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($specs)) {
            $specs = [];
        }

        // также specs[key] из FormData
        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'specs.') || (str_starts_with($key, 'specs[') && str_ends_with($key, ']'))) {
                $field = preg_replace('/^specs[\.\[]|\]$/', '', $key);
                $field = trim($field, '[]');
                $specs[$field] = $value;
            }
        }

        return $specs;
    }

    private function hasSpecKeys(Request $request, Category $category): bool
    {
        $fields = $category->fieldSchema() ?? [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if ($request->has("specs.$key") || $request->has("specs[$key]")) {
                return true;
            }
        }

        return false;
    }
}
