<?php

namespace Database\Seeders;

use App\Models\Lead;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    public function run(): void
    {
        $samples = [
            [
                'company_name' => 'ООО Пример Гофра (демо)',
                'phone' => '+7 495 111-22-33',
                'website' => 'https://example-gofra.ru',
                'city' => 'Москва',
                'region' => 'Москва',
                'inn' => '7707083893',
                'contact_person' => 'Менеджер продаж',
                'category_slug' => 'corrugated-boxes',
                'source' => 'manual',
                'source_query' => 'купить гофрокоробки оптом Москва',
                'call_status' => 'to_call',
                'notes' => 'Демо-лид. Удалить после тестов.',
            ],
            [
                'company_name' => 'УпакСервис МО (демо)',
                'phone' => '+7 926 000-00-01',
                'city' => 'Химки',
                'region' => 'Московская область',
                'category_slug' => 'corrugated-boxes',
                'source' => 'maps_manual',
                'source_url' => 'https://yandex.ru/maps',
                'call_status' => 'new',
                'notes' => 'Нашли вручную в картах — так и заносим source=maps_manual.',
            ],
        ];

        foreach ($samples as $row) {
            $norm = Lead::normalizePhone($row['phone'] ?? null);
            if ($norm && Lead::where('phone_normalized', $norm)->exists()) {
                continue;
            }
            Lead::create($row);
        }
    }
}
