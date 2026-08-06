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
        'sku',
        'supplier_product_code',
        'price_value',
        'price_hidden',
        'currency',
        'price_basis',
        'moq_value',
        'order_step',
        'stock_status',
        'production_lead_days',
        'delivery_lead_days',
        'delivery_regions',
        'pickup_available',
        'payment_terms',
        'vat_rate',
        'branding_available',
        'custom_manufacturing',
        'photo_path',
        'description_short',
        'specs',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_value' => 'decimal:2',
            'price_hidden' => 'boolean',
            'moq_value' => 'integer',
            'order_step' => 'integer',
            'production_lead_days' => 'integer',
            'delivery_lead_days' => 'integer',
            'delivery_regions' => 'array',
            'pickup_available' => 'boolean',
            'branding_available' => 'boolean',
            'custom_manufacturing' => 'boolean',
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
