<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSession extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'client_key',
        'structured_query',
        'last_match_ids',
        'status',
        'handoff_contact',
        'handoff_note',
        'handed_off_at',
    ];

    protected function casts(): array
    {
        return [
            'structured_query' => 'array',
            'last_match_ids' => 'array',
            'handed_off_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
