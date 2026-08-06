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
            'sku' => $this->sku,
            'supplier_product_code' => $this->supplier_product_code,
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
            'price_value' => $this->price_hidden ? null : (float) $this->price_value,
            'price_hidden' => (bool) $this->price_hidden,
            'currency' => $this->currency,
            'price_basis' => $this->price_basis,
            'moq_value' => $this->moq_value,
            'order_step' => (int) ($this->order_step ?? 1),
            'stock_status' => $this->stock_status,
            'production_lead_days' => $this->production_lead_days,
            'delivery_lead_days' => $this->delivery_lead_days,
            'delivery_regions' => $this->delivery_regions ?? [],
            'pickup_available' => $this->pickup_available,
            'payment_terms' => $this->payment_terms,
            'vat_rate' => $this->vat_rate,
            'branding_available' => $this->branding_available,
            'custom_manufacturing' => (bool) $this->custom_manufacturing,
            'photo_url' => $this->photo_url,
            'description_short' => $this->description_short,
            'specs' => $this->specs ?? [],
        ];
    }
}

