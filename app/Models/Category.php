<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'priority',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** Схема specs-полей из config/agora.php (null если slug неизвестен). */
    public function fieldSchema(): ?array
    {
        $categories = config('agora.categories', []);

        foreach ($categories as $cat) {
            if (($cat['slug'] ?? null) === $this->slug) {
                return $cat['fields'] ?? [];
            }
        }

        return null;
    }
}
