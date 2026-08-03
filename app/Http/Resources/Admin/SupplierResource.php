<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commercial_name' => $this->commercial_name,
            'legal_name' => $this->legal_name,
            'inn' => $this->inn,
            'legal_address' => $this->legal_address,
            'logo_url' => $this->logo_url,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'telegram' => $this->telegram,
            'is_active' => $this->is_active,
            'shipping_cities' => $this->whenLoaded('cities', fn () => $this->cities->pluck('name')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
