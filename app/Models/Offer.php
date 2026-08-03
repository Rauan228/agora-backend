<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected $fillable = [
        'supplier_id',
        'category_id',
        'offer_title',
        'price_value',
        'currency',
        'price_basis',
        'moq_value',
        'stock_status',
        'production_lead_days',
        'delivery_lead_days',
        'delivery_regions',
        'pickup_available',
        'payment_terms',
        'vat_rate',
        'branding_available',
        'photo_path',
        'description_short',
        'specs',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_value' => 'decimal:2',
            'moq_value' => 'integer',
            'production_lead_days' => 'integer',
            'delivery_lead_days' => 'integer',
            'delivery_regions' => 'array',
            'pickup_available' => 'boolean',
            'branding_available' => 'boolean',
            'specs' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? url('files/'.$this->photo_path) : null;
    }
}
