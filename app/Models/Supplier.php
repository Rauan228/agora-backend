<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'telegram',
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

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** Полный URL логотипа (или null). Удобно для админки и API. */
    public function getLogoUrlAttribute(): ?string
    {
        // Отдаём через наш роут /files/{path} (не зависит от симлинка public/storage).
        return $this->logo_path ? url('files/'.$this->logo_path) : null;
    }
}
