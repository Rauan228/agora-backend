<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Демо-офферы для AI-матчинга и витрины (идемпотентно по sku).
 */
class OfferSeeder extends Seeder
{
    public function run(): void
    {
        $suppliers = Supplier::query()->orderBy('id')->get();
        if ($suppliers->isEmpty()) {
            $this->command?->warn('OfferSeeder: no suppliers, skip');

            return;
        }

        $bySlug = Category::query()->get()->keyBy('slug');
        $s = fn (int $i) => $suppliers[$i % $suppliers->count()];

        $rows = [
            [
                'sku' => 'AI-BOX-0201-400',
                'supplier' => $s(0),
                'category' => 'corrugated-boxes',
                'offer_title' => 'Гофрокороб 400×300×200 четырёхклапанный Т-23',
                'price_value' => 28.50,
                'moq_value' => 100,
                'stock_status' => 'В наличии',
                'production_lead_days' => 3,
                'delivery_lead_days' => 2,
                'delivery_regions' => ['Москва', 'Московская область'],
                'branding_available' => true,
                'custom_manufacturing' => true,
                'description_short' => 'Классический FEFCO 0201 для e-com и склада.',
                'specs' => [
                    'box_type' => 'Четырёхклапанный',
                    'box_inner_length_mm' => 400,
                    'box_inner_width_mm' => 300,
                    'box_inner_height_mm' => 200,
                    'box_board_grade' => 'Т-23',
                    'box_flute_profile' => 'B',
                    'box_fefco_code' => '0201',
                    'box_ply_count' => '3',
                    'box_liner_color' => 'Бурый',
                    'box_print_available' => true,
                ],
            ],
            [
                'sku' => 'AI-BOX-SELF-350',
                'supplier' => $s(1),
                'category' => 'corrugated-boxes',
                'offer_title' => 'Самосборный короб 350×250×150 бурый',
                'price_value' => 22.00,
                'moq_value' => 500,
                'stock_status' => 'В наличии',
                'production_lead_days' => 5,
                'delivery_lead_days' => 3,
                'delivery_regions' => ['Москва', 'ЦФО'],
                'branding_available' => true,
                'description_short' => 'Самосбор для маркетплейсов, без скотча.',
                'specs' => [
                    'box_type' => 'Самосборный',
                    'box_inner_length_mm' => 350,
                    'box_inner_width_mm' => 250,
                    'box_inner_height_mm' => 150,
                    'box_board_grade' => 'Т-22',
                    'box_flute_profile' => 'E',
                    'box_liner_color' => 'Бурый',
                    'box_print_available' => true,
                ],
            ],
            [
                'sku' => 'AI-BOX-WHITE-500',
                'supplier' => $s(2),
                'category' => 'corrugated-boxes',
                'offer_title' => 'Гофрокороб 500×400×400 белый Т-24 с печатью',
                'price_value' => 55.00,
                'moq_value' => 200,
                'stock_status' => 'Под заказ',
                'production_lead_days' => 10,
                'delivery_lead_days' => 4,
                'delivery_regions' => ['Москва', 'Московская область', 'Россия'],
                'branding_available' => true,
                'custom_manufacturing' => true,
                'description_short' => 'Белый лайнер, печать 1+0, под брендинг.',
                'specs' => [
                    'box_type' => 'Четырёхклапанный',
                    'box_inner_length_mm' => 500,
                    'box_inner_width_mm' => 400,
                    'box_inner_height_mm' => 400,
                    'box_board_grade' => 'Т-24',
                    'box_flute_profile' => 'C',
                    'box_liner_color' => 'Белый',
                    'box_print_available' => true,
                ],
            ],
            [
                'sku' => 'AI-SHEET-T23',
                'supplier' => $s(0),
                'category' => 'corrugated-sheet',
                'offer_title' => 'Гофролист 1200×800 Т-23 профиль B',
                'price_value' => 45.00,
                'price_basis' => 'лист',
                'moq_value' => 50,
                'stock_status' => 'В наличии',
                'production_lead_days' => 2,
                'delivery_lead_days' => 2,
                'delivery_regions' => ['Москва', 'Московская область'],
                'description_short' => 'Листовой гофрокартон для высечки и прокладок.',
                'specs' => [
                    'sheet_format' => 'Лист',
                    'sheet_length_mm' => 1200,
                    'sheet_width_mm' => 800,
                    'board_grade' => 'Т-23',
                    'flute_profile' => 'B',
                ],
            ],
            [
                'sku' => 'AI-STRETCH-500',
                'supplier' => $s(1),
                'category' => 'stretch-film',
                'offer_title' => 'Стрейч-плёнка ручная 500 мм 20 мкм',
                'price_value' => 380.00,
                'price_basis' => 'рулон',
                'moq_value' => 6,
                'stock_status' => 'В наличии',
                'production_lead_days' => 1,
                'delivery_lead_days' => 2,
                'delivery_regions' => ['Москва', 'Россия'],
                'specs' => [
                    'stretch_type' => 'Ручная',
                    'stretch_width_mm' => 500,
                    'stretch_thickness_mkm' => 20,
                    'stretch_length_m' => 300,
                ],
            ],
            [
                'sku' => 'AI-TAPE-48',
                'supplier' => $s(2),
                'category' => 'packing-tape',
                'offer_title' => 'Скотч упаковочный 48 мм × 66 м',
                'price_value' => 45.00,
                'price_basis' => 'шт',
                'moq_value' => 36,
                'stock_status' => 'В наличии',
                'production_lead_days' => 1,
                'delivery_lead_days' => 1,
                'delivery_regions' => ['Москва', 'Московская область'],
                'branding_available' => true,
                'specs' => [
                    'tape_base_material' => 'BOPP',
                    'tape_adhesive_type' => 'Акрил',
                    'tape_width_mm' => 48,
                    'tape_length_m' => 66,
                ],
            ],
            [
                'sku' => 'AI-BOX-MAIL-300',
                'supplier' => $s(0),
                'category' => 'corrugated-boxes',
                'offer_title' => 'Почтовый короб 300×200×100',
                'price_value' => 18.00,
                'moq_value' => 200,
                'stock_status' => 'В наличии',
                'production_lead_days' => 4,
                'delivery_lead_days' => 2,
                'delivery_regions' => ['Москва'],
                'specs' => [
                    'box_type' => 'Почтовый',
                    'box_inner_length_mm' => 300,
                    'box_inner_width_mm' => 200,
                    'box_inner_height_mm' => 100,
                    'box_board_grade' => 'Т-21',
                    'box_flute_profile' => 'E',
                    'box_liner_color' => 'Бурый',
                    'box_print_available' => false,
                ],
            ],
            [
                'sku' => 'AI-BOX-CUSTOM-ANY',
                'supplier' => $s(1),
                'category' => 'corrugated-boxes',
                'offer_title' => 'Гофрокороб на заказ любой размер (Москва)',
                'price_value' => 1.00,
                'price_hidden' => true,
                'moq_value' => 50,
                'stock_status' => 'Под заказ',
                'production_lead_days' => 7,
                'delivery_lead_days' => 3,
                'delivery_regions' => ['Москва', 'Московская область', 'ЦФО'],
                'branding_available' => true,
                'custom_manufacturing' => true,
                'description_short' => 'Индивидуальный раскрой, печать, сложные конструкции.',
                'specs' => [
                    'box_type' => 'Другой',
                    'box_inner_length_mm' => 400,
                    'box_inner_width_mm' => 300,
                    'box_inner_height_mm' => 200,
                    'box_board_grade' => 'Т-23',
                    'box_flute_profile' => 'BC',
                    'box_print_available' => true,
                ],
            ],
        ];

        foreach ($rows as $row) {
            $cat = $bySlug->get($row['category']);
            if (! $cat) {
                continue;
            }

            Offer::updateOrCreate(
                ['sku' => $row['sku']],
                [
                    'supplier_id' => $row['supplier']->id,
                    'category_id' => $cat->id,
                    'offer_title' => $row['offer_title'],
                    'sku' => $row['sku'],
                    'price_value' => $row['price_value'],
                    'price_hidden' => $row['price_hidden'] ?? false,
                    'currency' => 'RUB',
                    'price_basis' => $row['price_basis'] ?? 'шт',
                    'moq_value' => $row['moq_value'],
                    'order_step' => 1,
                    'stock_status' => $row['stock_status'],
                    'production_lead_days' => $row['production_lead_days'] ?? null,
                    'delivery_lead_days' => $row['delivery_lead_days'] ?? null,
                    'delivery_regions' => $row['delivery_regions'],
                    'pickup_available' => true,
                    'payment_terms' => 'Безнал',
                    'vat_rate' => '20',
                    'branding_available' => $row['branding_available'] ?? false,
                    'custom_manufacturing' => $row['custom_manufacturing'] ?? false,
                    'description_short' => $row['description_short'] ?? null,
                    'specs' => $row['specs'],
                    'is_active' => true,
                ]
            );
        }
    }
}
