<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'phone' => $this->phone,
            'phone_normalized' => $this->phone_normalized,
            'phone_extra' => $this->phone_extra,
            'email' => $this->email,
            'website' => $this->website,
            'city' => $this->city,
            'region' => $this->region,
            'inn' => $this->inn,
            'contact_person' => $this->contact_person,
            'category_slug' => $this->category_slug,
            'source' => $this->source,
            'source_url' => $this->source_url,
            'source_query' => $this->source_query,
            'external_id' => $this->external_id,
            'call_status' => $this->call_status,
            'notes' => $this->notes,
            'call_notes' => $this->call_notes,
            'last_called_at' => $this->last_called_at?->toIso8601String(),
            'next_call_at' => $this->next_call_at?->toIso8601String(),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'commercial_name' => $this->supplier->commercial_name,
            ]),
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
