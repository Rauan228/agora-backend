<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    public const SOURCES = [
        'manual',
        'csv',
        'kontur',
        'website',
        'maps_manual',
        'ads_manual',
        'other',
    ];

    public const CALL_STATUSES = [
        'new',
        'to_call',
        'no_answer',
        'callback',
        'interested',
        'sent_kp',
        'onboarded',
        'rejected',
        'wrong_number',
        'duplicate',
    ];

    protected $fillable = [
        'company_name',
        'phone',
        'phone_normalized',
        'phone_extra',
        'email',
        'website',
        'city',
        'region',
        'inn',
        'contact_person',
        'category_slug',
        'source',
        'source_url',
        'source_query',
        'external_id',
        'call_status',
        'notes',
        'call_notes',
        'last_called_at',
        'next_call_at',
        'supplier_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_called_at' => 'datetime',
            'next_call_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Нормализация телефона для дедупа: только цифры, 11 с 7 для РФ. */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7'.$digits;
        }

        return $digits;
    }

    protected static function booted(): void
    {
        static::saving(function (Lead $lead) {
            $lead->phone_normalized = self::normalizePhone($lead->phone);
            if ($lead->inn) {
                $lead->inn = preg_replace('/\D+/', '', $lead->inn) ?: null;
            }
        });
    }
}
