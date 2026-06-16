<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Supplier extends Model
{
    protected $fillable = [
        'commercial_name',
        'legal_name',
        'inn',
        'legal_address',
        'logo_path',
        'contact_person',
        'phone',
        'email',
        'website',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Города отгрузки этого поставщика. */
    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class);
    }

    /** Полный URL логотипа (или null). Удобно для админки и API. */
    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::url($this->logo_path) : null;
    }
}
