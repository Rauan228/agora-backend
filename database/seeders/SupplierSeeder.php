<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Тестовые поставщики (взяты из текущего фронта-мока agora-trade).
     * ИНН — валидные тестовые значения (проходят проверку контрольной суммы).
     */
    public function run(): void
    {
        $suppliers = [
            [
                'commercial_name' => 'ПаллетПром',
                'legal_name'      => 'ООО «ПаллетПром»',
                'inn'             => '7707083893',
                'legal_address'   => 'г. Москва, ул. Складская, д. 1',
                'contact_person'  => 'Иван Петров',
                'phone'           => '+7 495 100-10-10',
                'email'           => 'sales@palletprom.ru',
                'website'         => 'https://palletprom.ru',
                'cities'          => ['Москва'],
            ],
            [
                'commercial_name' => 'УпакСервис',
                'legal_name'      => 'ООО «УпакСервис»',
                'inn'             => '7802849641',
                'legal_address'   => 'г. Санкт-Петербург, пр. Промышленный, д. 5',
                'contact_person'  => 'Мария Сидорова',
                'phone'           => '+7 812 200-20-20',
                'email'           => 'info@upakservice.ru',
                'website'         => 'https://upakservice.ru',
                'cities'          => ['Санкт-Петербург', 'Москва'],
            ],
            [
                'commercial_name' => 'ЛогистикПак',
                'legal_name'      => 'ООО «ЛогистикПак»',
                'inn'             => '1655234562',
                'legal_address'   => 'г. Казань, ул. Транспортная, д. 12',
                'contact_person'  => 'Айдар Галиев',
                'phone'           => '+7 843 300-30-30',
                'email'           => 'order@logistikpak.ru',
                'website'         => 'https://logistikpak.ru',
                'cities'          => ['Казань', 'Москва'],
            ],
        ];

        foreach ($suppliers as $data) {
            $cityNames = $data['cities'];
            unset($data['cities']);

            $supplier = Supplier::updateOrCreate(
                ['inn' => $data['inn']],   // ищем по ИНН, чтобы сидер был идемпотентным
                $data,
            );

            // создаём города из справочника и привязываем
            $cityIds = collect($cityNames)
                ->map(fn ($name) => City::firstOrCreate(['name' => $name])->id)
                ->all();

            $supplier->cities()->sync($cityIds);
        }
    }
}
