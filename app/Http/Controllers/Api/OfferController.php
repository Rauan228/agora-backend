<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use Illuminate\Http\Request;

/** Публичное API офферов для витрины. */
class OfferController extends Controller
{
    public function index(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $offers = Offer::query()
            ->where('is_active', true)
            ->with(['supplier', 'category'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q');
                $query->where('offer_title', 'like', "%{$q}%");
            })
            ->when($request->filled('category'), function ($query) use ($request) {
                $query->whereHas('category', fn ($c) => $c->where('slug', $request->string('category')));
            })
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('supplier_id'), fn ($query) => $query->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('stock_status'), fn ($query) => $query->where('stock_status', $request->string('stock_status')))
            ->when($request->filled('region'), function ($query) use ($request) {
                $region = $request->string('region');
                $query->whereJsonContains('delivery_regions', (string) $region);
            })
            ->orderBy('price_value')
            ->paginate($perPage);

        return OfferResource::collection($offers);
    }

    public function show(Offer $offer)
    {
        abort_unless($offer->is_active, 404);

        return new OfferResource($offer->load(['supplier', 'category']));
    }
}
