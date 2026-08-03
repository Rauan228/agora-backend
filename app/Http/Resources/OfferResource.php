<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Публичный формат оффера для витрины. */
class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offer_title' => $this->offer_title,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'commercial_name' => $this->supplier->commercial_name,
                'logo_url' => $this->supplier->logo_url,
            ]),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'slug' => $this->category->slug,
                'name' => $this->category->name,
            ]),
            'price_value' => (float) $this->price_value,
            'currency' => $this->currency,
            'price_basis' => $this->price_basis,
            'moq_value' => $this->moq_value,
            'stock_status' => $this->stock_status,
            'production_lead_days' => $this->production_lead_days,
            'delivery_lead_days' => $this->delivery_lead_days,
            'delivery_regions' => $this->delivery_regions ?? [],
            'pickup_available' => $this->pickup_available,
            'payment_terms' => $this->payment_terms,
            'vat_rate' => $this->vat_rate,
            'branding_available' => $this->branding_available,
            'photo_url' => $this->photo_url,
            'description_short' => $this->description_short,
            'specs' => $this->specs ?? [],
        ];
    }
}
