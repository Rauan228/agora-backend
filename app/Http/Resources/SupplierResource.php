<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Формат поставщика для публичного API (то, что видит фронт).
 * Внутренние поля (timestamps, сырой logo_path) не отдаём.
 */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'commercial_name' => $this->commercial_name,
            'legal_name'      => $this->legal_name,
            'inn'             => $this->inn,
            'legal_address'   => $this->legal_address,
            'logo_url'        => $this->logo_url,
            'contact' => [
                'person'  => $this->contact_person,
                'phone'   => $this->phone,
                'email'    => $this->email,
                'website'  => $this->website,
                'telegram' => $this->telegram,
            ],
            'shipping_cities' => $this->whenLoaded('cities', fn () => $this->cities->pluck('name')),
        ];
    }
}
