<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\City;
use App\Models\Offer;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Disposable fat catalog for AI matching tests.
 *
 * Does NOT touch live clients (ПаллетПром / УпакСервис / SKU AI-*).
 * All rows are tagged: offer.sku = SEED-…, supplier.email = *@seed.agora.local
 *
 *   php artisan agora:demo-catalog
 *   php artisan agora:demo-catalog --wipe
 */
class DemoCatalogSeeder extends Seeder
{
    public const SKU_PREFIX = 'SEED-';

    public const EMAIL_DOMAIN = 'seed.agora.local';

    /**
     * 10 factories. Each has 5 *different* product families (not clones).
     * Mix is intentional: some close a box+sheet kit, some cannot.
     *
     * @return list<array<string, mixed>>
     */
    public static function factories(): array
    {
        return [
            [
                'code' => 'SVP',
                'commercial_name' => 'СеверПак',
                'legal_name' => 'ООО «СеверПак»',
                'inn_stem' => '773331010',
                'address' => 'г. Москва, ул. Складочная, д. 14',
                'phone' => '+7 495 111-10-10',
                'cities' => ['Москва'],
                'lines' => ['boxes_standard', 'sheets_std', 'stretch_hand', 'tape_acryl', 'bubble_std'],
            ],
            [
                'code' => 'GMS',
                'commercial_name' => 'ГофроМастер',
                'legal_name' => 'ООО «ГофроМастер»',
                'inn_stem' => '773331011',
                'address' => 'МО, г. Подольск, Промышленная, д. 8',
                'phone' => '+7 496 222-20-20',
                'cities' => ['Москва'],
                'lines' => ['boxes_wide', 'sheets_big', 'fillers_paper', 'foam_roll', 'tape_hotmelt'],
            ],
            [
                'code' => 'KVT',
                'commercial_name' => 'КартонВосток',
                'legal_name' => 'ООО «КартонВосток»',
                'inn_stem' => '773331012',
                'address' => 'г. Москва, Остаповский пр-д, д. 3',
                'phone' => '+7 495 333-30-30',
                'cities' => ['Москва'],
                'lines' => ['boxes_white', 'sheets_premium', 'labels_eco', 'tape_acryl', 'shrink_pof'],
            ],
            [
                'code' => 'PLP',
                'commercial_name' => 'ПлёнкаПро',
                'legal_name' => 'ООО «ПлёнкаПро»',
                'inn_stem' => '773331013',
                'address' => 'г. Москва, Варшавское ш., д. 125',
                'phone' => '+7 495 444-40-40',
                'cities' => ['Москва'],
                'lines' => ['stretch_machine', 'shrink_pvc', 'bubble_3l', 'foam_sheet', 'tape_hotmelt'],
            ],
            [
                'code' => 'TSK',
                'commercial_name' => 'ТараСклад',
                'legal_name' => 'ООО «ТараСклад»',
                'inn_stem' => '773331014',
                'address' => 'г. Москва, ул. Подольских Курсантов, д. 17',
                'phone' => '+7 495 555-50-50',
                'cities' => ['Москва'],
                'lines' => ['boxes_archive', 'pallets_wood', 'rpc_euro', 'tape_acryl', 'stretch_hand'],
            ],
            [
                'code' => 'ECM',
                'commercial_name' => 'eComУпак',
                'legal_name' => 'ООО «еКомУпак»',
                'inn_stem' => '773331015',
                'address' => 'г. Москва, Алтуфьевское ш., д. 37',
                'phone' => '+7 495 666-60-60',
                'cities' => ['Москва'],
                'lines' => ['boxes_mail', 'courier_std', 'zip_pe', 'labels_top', 'bubble_std'],
            ],
            [
                'code' => 'LSP',
                'commercial_name' => 'ЛистПром',
                'legal_name' => 'ООО «ЛистПром»',
                'inn_stem' => '773331016',
                'address' => 'МО, г. Чехов, Комсомольская, д. 2',
                'phone' => '+7 496 777-70-70',
                'cities' => ['Москва'],
                'lines' => ['sheets_std', 'foam_roll', 'fillers_air', 'stretch_hand', 'tape_acryl'],
            ],
            [
                'code' => 'MGF',
                'commercial_name' => 'МосГофра',
                'legal_name' => 'ООО «МосГофра»',
                'inn_stem' => '773331017',
                'address' => 'г. Москва, Рязанский пр-т, д. 86',
                'phone' => '+7 495 888-80-80',
                'cities' => ['Москва'],
                'lines' => ['boxes_standard', 'sheets_std', 'tape_acryl', 'labels_eco', 'pallets_wood'],
            ],
            [
                'code' => 'URG',
                'commercial_name' => 'УпакРегион',
                'legal_name' => 'ООО «УпакРегион»',
                'inn_stem' => '165331018',
                'address' => 'г. Казань, Техническая, д. 11',
                'phone' => '+7 843 999-90-90',
                'cities' => ['Казань', 'Москва'],
                'lines' => ['boxes_wide', 'sheets_premium', 'stretch_machine', 'shrink_pof', 'tape_hotmelt'],
            ],
            [
                'code' => 'KRF',
                'commercial_name' => 'КрафтПак',
                'legal_name' => 'ООО «КрафтПак»',
                'inn_stem' => '773331019',
                'address' => 'г. Москва, ул. Нижние Поля, д. 29',
                'phone' => '+7 495 101-01-01',
                'cities' => ['Москва'],
                'lines' => ['boxes_kraft', 'sheets_cheap', 'bubble_std', 'foam_sheet', 'zip_pp'],
            ],
        ];
    }

    public function run(): void
    {
        $bySlug = Category::query()->get()->keyBy('slug');
        if ($bySlug->isEmpty()) {
            $this->command?->error('DemoCatalogSeeder: categories missing. Seed CategorySeeder first.');

            return;
        }

        $createdOffers = 0;
        foreach (self::factories() as $factory) {
            $supplier = $this->upsertSupplier($factory);
            foreach ($factory['lines'] as $line) {
                foreach ($this->skusFor($factory['code'], $line) as $row) {
                    $cat = $bySlug->get($row['category']);
                    if (! $cat) {
                        continue;
                    }
                    Offer::updateOrCreate(
                        ['sku' => $row['sku']],
                        [
                            'supplier_id' => $supplier->id,
                            'category_id' => $cat->id,
                            'offer_title' => $row['title'],
                            'sku' => $row['sku'],
                            'supplier_product_code' => $row['sku'],
                            'price_value' => $row['price'],
                            'price_hidden' => $row['price_hidden'] ?? false,
                            'currency' => 'RUB',
                            'price_basis' => $row['basis'],
                            'moq_value' => $row['moq'],
                            'order_step' => 1,
                            'stock_status' => $row['stock'],
                            'production_lead_days' => $row['prod'],
                            'delivery_lead_days' => $row['deliv'],
                            'delivery_regions' => $row['regions'],
                            'pickup_available' => true,
                            'payment_terms' => 'Безнал',
                            'vat_rate' => '20',
                            'branding_available' => $row['branding'] ?? false,
                            'custom_manufacturing' => $row['custom'] ?? false,
                            'description_short' => $row['desc'],
                            'specs' => $row['specs'],
                            'is_active' => true,
                        ]
                    );
                    $createdOffers++;
                }
            }
        }

        $this->command?->info("Demo catalog ready: 10 suppliers, {$createdOffers} SEED- offers.");
    }

    public static function wipe(): int
    {
        $offers = Offer::query()->where('sku', 'like', self::SKU_PREFIX.'%')->delete();
        $suppliers = Supplier::query()->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->delete();

        return $offers + $suppliers;
    }

    /**
     * @param  array<string, mixed>  $factory
     */
    private function upsertSupplier(array $factory): Supplier
    {
        $inn = self::inn10($factory['inn_stem']);
        $supplier = Supplier::updateOrCreate(
            ['inn' => $inn],
            [
                'commercial_name' => $factory['commercial_name'],
                'legal_name' => $factory['legal_name'],
                'inn' => $inn,
                'legal_address' => $factory['address'],
                'contact_person' => 'Отдел продаж',
                'phone' => $factory['phone'],
                'email' => strtolower($factory['code']).'@'.self::EMAIL_DOMAIN,
                'website' => 'https://'.strtolower($factory['code']).'.example',
                'telegram' => '@'.strtolower($factory['code']),
                'is_active' => true,
            ]
        );

        $cityIds = collect($factory['cities'])
            ->map(fn (string $name) => City::firstOrCreate(['name' => $name])->id)
            ->all();
        $supplier->cities()->sync($cityIds);

        return $supplier;
    }

    /** 10-digit INN with a valid checksum. Stem is 9 digits. */
    public static function inn10(string $stem9): string
    {
        $coef = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $digits = array_map('intval', str_split($stem9));
        $sum = 0;
        foreach ($coef as $i => $k) {
            $sum += $k * $digits[$i];
        }

        return $stem9.(($sum % 11) % 10);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function skusFor(string $code, string $line): array
    {
        return match ($line) {
            'boxes_standard' => $this->boxes($code, [
                ['400×300×200', 'Четырёхклапанный', 'Т-23', 'B', 'Бурый', '0201', 28.5, 100],
                ['420×320×210', 'Четырёхклапанный', 'Т-23', 'C', 'Бурый', '0201', 31.0, 100],
                ['380×280×190', 'Самосборный', 'Т-22', 'E', 'Бурый', '0427', 24.0, 200],
                ['450×350×250', 'Четырёхклапанный', 'Т-24', 'B', 'Бурый', '0201', 39.0, 80],
                ['330×230×160', 'Почтовый', 'Т-21', 'E', 'Бурый', '0201', 16.5, 300],
            ]),
            'boxes_wide' => $this->boxes($code, [
                ['600×400×300', 'Четырёхклапанный', 'Т-24', 'C', 'Бурый', '0201', 52.0, 80],
                ['400×300×200', 'Самосборный', 'Т-23', 'B', 'Бурый', '0427', 27.0, 150],
                ['700×500×400', 'Четырёхклапанный', 'П-32', 'BC', 'Бурый', '0201', 88.0, 50],
                ['500×400×300', 'Крышка-дно', 'Т-24', 'C', 'Бурый', '0300', 64.0, 60],
                ['550×350×280', 'Четырёхклапанный', 'Т-23', 'B', 'Крафт', '0201', 48.0, 80],
            ]),
            'boxes_white' => $this->boxes($code, [
                ['400×300×200', 'Четырёхклапанный', 'Т-24', 'B', 'Белый', '0201', 36.0, 100, true],
                ['500×400×400', 'Четырёхклапанный', 'Т-24', 'C', 'Белый', '0201', 58.0, 80, true],
                ['350×250×150', 'Самосборный', 'Т-23', 'E', 'Белый', '0427', 29.0, 200, true],
                ['300×200×150', 'Почтовый', 'Т-22', 'E', 'Белый', '0201', 21.0, 250, true],
                ['450×300×220', 'Четырёхклапанный', 'П-31', 'B', 'Белый', '0201', 44.0, 80, true],
            ]),
            'boxes_archive' => $this->boxes($code, [
                ['330×230×230', 'Архивный', 'Т-23', 'B', 'Бурый', '0201', 34.0, 100],
                ['400×300×300', 'Архивный', 'Т-24', 'C', 'Бурый', '0201', 46.0, 80],
                ['280×180×180', 'Архивный', 'Т-22', 'E', 'Бурый', '0201', 22.0, 150],
                ['500×350×300', 'Крышка-дно', 'Т-24', 'B', 'Бурый', '0300', 61.0, 60],
                ['360×260×240', 'Четырёхклапанный', 'Т-23', 'C', 'Бурый', '0201', 33.0, 100],
            ]),
            'boxes_mail' => $this->boxes($code, [
                ['300×200×100', 'Почтовый', 'Т-21', 'E', 'Бурый', '0201', 14.5, 400],
                ['250×170×80', 'Почтовый', 'Т-21', 'E', 'Бурый', '0201', 11.0, 500],
                ['350×250×100', 'Почтовый', 'Т-22', 'E', 'Бурый', '0201', 17.5, 300],
                ['400×300×120', 'Самосборный', 'Т-22', 'E', 'Бурый', '0427', 23.0, 200],
                ['220×160×70', 'Почтовый', 'Т-21', 'E', 'Бурый', '0201', 9.5, 600],
            ]),
            'boxes_kraft' => $this->boxes($code, [
                ['400×300×200', 'Четырёхклапанный', 'Т-22', 'B', 'Крафт', '0201', 22.0, 200],
                ['380×280×180', 'Самосборный', 'Т-21', 'E', 'Крафт', '0427', 18.5, 300],
                ['450×300×200', 'Четырёхклапанный', 'Т-23', 'B', 'Крафт', '0201', 26.0, 150],
                ['320×220×150', 'Почтовый', 'Т-21', 'E', 'Крафт', '0201', 13.0, 400],
                ['500×350×250', 'Четырёхклапанный', 'Т-22', 'C', 'Крафт', '0201', 31.0, 120],
            ]),
            'sheets_std' => $this->sheets($code, [
                ['1200×800', 'Т-23', 'B', 45.0],
                ['1050×1050', 'Т-23', 'C', 52.0],
                ['1000×700', 'Т-22', 'B', 38.0],
                ['1250×800', 'Т-24', 'B', 49.0],
                ['1100×900', 'Т-23', 'E', 41.0],
            ]),
            'sheets_big' => $this->sheets($code, [
                ['2000×1000', 'Т-24', 'C', 78.0],
                ['1800×1200', 'П-32', 'BC', 96.0],
                ['1600×1000', 'Т-23', 'B', 67.0],
                ['2100×1050', 'Т-24', 'C', 84.0],
                ['1500×1500', 'Т-23', 'BC', 88.0],
            ]),
            'sheets_premium' => $this->sheets($code, [
                ['1200×800', 'Т-24', 'B', 58.0],
                ['1250×850', 'П-31', 'C', 66.0],
                ['1400×1000', 'Т-24', 'C', 74.0],
                ['1000×800', 'Т-23', 'B', 51.0],
                ['1300×900', 'П-32', 'BC', 82.0],
            ]),
            'sheets_cheap' => $this->sheets($code, [
                ['1000×700', 'Т-21', 'E', 29.0],
                ['1100×800', 'Т-22', 'B', 33.0],
                ['1200×800', 'Т-22', 'C', 36.0],
                ['900×600', 'Т-21', 'E', 24.0],
                ['1050×750', 'Т-22', 'B', 31.0],
            ]),
            'stretch_hand' => $this->stretch($code, 'Ручная', [500, 450, 250], [17, 20, 23], 300),
            'stretch_machine' => $this->stretch($code, 'Машинная', [500, 450], [20, 23, 17], 1500),
            'tape_acryl' => $this->tape($code, 'Акрил', [48, 50, 72], [50, 66, 100]),
            'tape_hotmelt' => $this->tape($code, 'Hot melt', [48, 50, 75], [36, 66, 150]),
            'bubble_std' => $this->bubble($code, '2', [1200, 1000, 600], [50, 100]),
            'bubble_3l' => $this->bubble($code, '3', [1500, 1200], [50, 80]),
            'foam_roll' => $this->foam($code, 'Рулон', [2, 3, 5], [1050, 1200]),
            'foam_sheet' => $this->foam($code, 'Лист', [5, 8, 10], [1000]),
            'shrink_pof' => $this->shrink($code, 'ПОФ', [300, 400, 500], [12, 15, 19]),
            'shrink_pvc' => $this->shrink($code, 'ПВХ', [250, 350, 450], [15, 20, 25]),
            'fillers_paper' => $this->fillers($code, 'Бумажный', 'Бумага'),
            'fillers_air' => $this->fillers($code, 'Воздушные подушки', 'ПЭ'),
            'labels_eco' => $this->labels($code, 'Термо ЭКО', [[58, 40], [58, 60], [43, 25], [100, 50], [75, 50]]),
            'labels_top' => $this->labels($code, 'Термо ТОП', [[58, 40], [80, 50], [100, 75], [43, 25], [58, 30]]),
            'courier_std' => $this->courier($code, [[240, 320], [300, 400], [400, 500], [200, 280], [350, 450]]),
            'zip_pe' => $this->zip($code, 'ПВД', [[100, 150], [150, 200], [200, 300], [80, 120], [250, 350]]),
            'zip_pp' => $this->zip($code, 'ПП', [[80, 120], [120, 180], [200, 250], [60, 80], [150, 220]]),
            'pallets_wood' => $this->pallets($code, 'Дерево', [[1200, 800], [1200, 1000], [800, 600]]),
            'rpc_euro' => $this->rpc($code, 'Евроконтейнер', [[600, 400, 300], [400, 300, 220], [800, 600, 420]]),
            default => [],
        };
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function boxes(string $code, array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $r) {
            [$size, $type, $grade, $flute, $color, $fefco, $price, $moq] = $r;
            $print = (bool) ($r[8] ?? false);
            [$l, $w, $h] = array_map('intval', explode('×', $size));
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-BOX-".($i + 1),
                category: 'corrugated-boxes',
                title: "Гофрокороб {$size} {$type} {$grade}",
                price: $price,
                basis: 'шт',
                moq: $moq,
                stock: 'В наличии',
                prod: 3 + ($i % 4),
                deliv: 2,
                regions: ['Москва', 'Московская область'],
                branding: $print,
                custom: $type === 'Другой',
                desc: "{$type}, марка {$grade}, профиль {$flute}, лайнер {$color}.",
                specs: [
                    'box_type' => $type,
                    'box_inner_length_mm' => $l,
                    'box_inner_width_mm' => $w,
                    'box_inner_height_mm' => $h,
                    'box_board_grade' => $grade,
                    'box_flute_profile' => $flute,
                    'box_fefco_code' => $fefco,
                    'box_ply_count' => str_starts_with($grade, 'П') ? '5' : '3',
                    'box_liner_color' => $color,
                    'box_print_available' => $print,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0:string,1:string,2:string,3:float}>  $rows
     * @return list<array<string, mixed>>
     */
    private function sheets(string $code, array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $r) {
            [$size, $grade, $flute, $price] = $r;
            [$l, $w] = array_map('intval', explode('×', $size));
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-SHT-".($i + 1),
                category: 'corrugated-sheet',
                title: "Гофролист {$size} {$grade} профиль {$flute}",
                price: $price,
                basis: 'лист',
                moq: 50,
                stock: 'В наличии',
                prod: 2 + ($i % 3),
                deliv: 2,
                regions: ['Москва', 'Московская область'],
                desc: "Листовой гофрокартон {$grade}, профиль {$flute}.",
                specs: [
                    'sheet_format' => 'Лист',
                    'sheet_length_mm' => $l,
                    'sheet_width_mm' => $w,
                    'board_grade' => $grade,
                    'flute_profile' => $flute,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<int>  $widths
     * @param  list<int>  $thicks
     * @return list<array<string, mixed>>
     */
    private function stretch(string $code, string $type, array $widths, array $thicks, int $length): array
    {
        $out = [];
        $n = 0;
        foreach ($widths as $w) {
            foreach ($thicks as $t) {
                if ($n >= 5) {
                    break 2;
                }
                $n++;
                $out[] = $this->row(
                    sku: self::SKU_PREFIX."{$code}-STR-{$n}",
                    category: 'stretch-film',
                    title: "Стрейч {$type} {$w} мм {$t} мкм",
                    price: $type === 'Машинная' ? 890 + $n * 40 : 320 + $n * 25,
                    basis: 'рулон',
                    moq: $type === 'Машинная' ? 4 : 6,
                    stock: 'В наличии',
                    prod: 1,
                    deliv: 2,
                    regions: ['Москва', 'Россия'],
                    desc: "Стрейч-плёнка, намотка {$type}.",
                    specs: [
                        'stretch_type' => $type,
                        'stretch_width_mm' => $w,
                        'stretch_thickness_mkm' => $t,
                        'stretch_length_m' => $length,
                    ],
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $widths
     * @param  list<int>  $lengths
     * @return list<array<string, mixed>>
     */
    private function tape(string $code, string $glue, array $widths, array $lengths): array
    {
        $out = [];
        $n = 0;
        foreach ($widths as $w) {
            foreach ($lengths as $len) {
                if ($n >= 5) {
                    break 2;
                }
                $n++;
                $out[] = $this->row(
                    sku: self::SKU_PREFIX."{$code}-TAP-{$n}",
                    category: 'packing-tape',
                    title: "Скотч {$w} мм × {$len} м, {$glue}",
                    price: 28 + $n * 6,
                    basis: 'шт',
                    moq: 36,
                    stock: 'В наличии',
                    prod: 1,
                    deliv: 1,
                    regions: ['Москва', 'Московская область'],
                    branding: $w >= 50,
                    desc: "Упаковочный скотч, клей {$glue}.",
                    specs: [
                        'tape_base_material' => 'BOPP',
                        'tape_adhesive_type' => $glue,
                        'tape_width_mm' => $w,
                        'tape_length_m' => $len,
                    ],
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $widths
     * @param  list<int>  $lengths
     * @return list<array<string, mixed>>
     */
    private function bubble(string $code, string $layers, array $widths, array $lengths): array
    {
        $out = [];
        $n = 0;
        foreach ($widths as $w) {
            foreach ($lengths as $len) {
                if ($n >= 5) {
                    break 2;
                }
                $n++;
                $out[] = $this->row(
                    sku: self::SKU_PREFIX."{$code}-BBL-{$n}",
                    category: 'bubble-wrap',
                    title: "ВПП {$w} мм × {$len} м, {$layers} слоя",
                    price: 420 + $n * 55,
                    basis: 'рулон',
                    moq: 2,
                    stock: 'В наличии',
                    prod: 2,
                    deliv: 2,
                    regions: ['Москва', 'ЦФО'],
                    desc: 'Воздушно-пузырчатая плёнка.',
                    specs: [
                        'bubble_width_mm' => $w,
                        'bubble_length_m' => $len,
                        'bubble_layers' => $layers,
                        'bubble_diameter_mm' => $layers === '3' ? 10 : 6,
                    ],
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<int|float>  $thicks
     * @param  list<int>  $widths
     * @return list<array<string, mixed>>
     */
    private function foam(string $code, string $form, array $thicks, array $widths): array
    {
        $out = [];
        $n = 0;
        foreach ($thicks as $t) {
            foreach ($widths as $w) {
                if ($n >= 5) {
                    break 2;
                }
                $n++;
                $out[] = $this->row(
                    sku: self::SKU_PREFIX."{$code}-FOA-{$n}",
                    category: 'foam-pe',
                    title: "ППЭ {$form} {$t} мм, ширина {$w}",
                    price: $form === 'Лист' ? 18 + $n * 3 : 210 + $n * 30,
                    basis: $form === 'Лист' ? 'лист' : 'рулон',
                    moq: $form === 'Лист' ? 20 : 2,
                    stock: 'В наличии',
                    prod: 2,
                    deliv: 2,
                    regions: ['Москва', 'Московская область'],
                    desc: "Вспененный полиэтилен, {$form}.",
                    specs: [
                        'foam_pe_form' => $form,
                        'foam_pe_thickness_mm' => $t,
                        'foam_pe_width_mm' => $w,
                        'foam_pe_length_m' => $form === 'Рулон' ? 50 : 1,
                    ],
                );
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $widths
     * @param  list<int>  $thicks
     * @return list<array<string, mixed>>
     */
    private function shrink(string $code, string $material, array $widths, array $thicks): array
    {
        $out = [];
        $n = 0;
        foreach ($widths as $w) {
            foreach ($thicks as $t) {
                if ($n >= 5) {
                    break 2;
                }
                $n++;
                $out[] = $this->row(
                    sku: self::SKU_PREFIX."{$code}-SHR-{$n}",
                    category: 'shrink-film',
                    title: "Термоусадка {$material} {$w} мм {$t} мкм",
                    price: 640 + $n * 45,
                    basis: 'кг',
                    moq: 10,
                    stock: 'В наличии',
                    prod: 3,
                    deliv: 2,
                    regions: ['Москва', 'Россия'],
                    desc: "Термоусадочная плёнка {$material}.",
                    specs: [
                        'shrink_material' => $material,
                        'shrink_format' => 'Полотно',
                        'shrink_width_mm' => $w,
                        'shrink_thickness_mkm' => $t,
                    ],
                );
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function fillers(string $code, string $type, string $material): array
    {
        $out = [];
        foreach ([5, 10, 20, 30, 50] as $i => $vol) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-FIL-".($i + 1),
                category: 'fillers',
                title: "Наполнитель {$type} {$vol} л",
                price: 90 + $vol * 4,
                basis: 'упаковка',
                moq: 4,
                stock: 'В наличии',
                prod: 2,
                deliv: 2,
                regions: ['Москва', 'Московская область'],
                desc: "Наполнитель для пустот: {$type}.",
                specs: [
                    'filler_type' => $type,
                    'filler_material' => $material,
                    'filler_pack_volume_l' => $vol,
                    'filler_pack_weight_kg' => round($vol * 0.08, 2),
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0:int,1:int}>  $sizes
     * @return list<array<string, mixed>>
     */
    private function labels(string $code, string $print, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $i => [$w, $h]) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-LBL-".($i + 1),
                category: 'thermal-labels',
                title: "Термоэтикетка {$print} {$w}×{$h} мм",
                price: 180 + $i * 25,
                basis: 'рулон',
                moq: 8,
                stock: 'В наличии',
                prod: 2,
                deliv: 1,
                regions: ['Москва', 'ЦФО'],
                desc: "Этикетка {$print}.",
                specs: [
                    'label_print_type' => $print,
                    'label_width_mm' => $w,
                    'label_height_mm' => $h,
                    'labels_per_roll' => 500 + $i * 250,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0:int,1:int}>  $sizes
     * @return list<array<string, mixed>>
     */
    private function courier(string $code, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $i => [$w, $h]) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-CUR-".($i + 1),
                category: 'courier-bags',
                title: "Курьерский пакет {$w}×{$h} мм",
                price: 3.2 + $i * 0.6,
                basis: 'шт',
                moq: 500,
                stock: 'В наличии',
                prod: 4,
                deliv: 2,
                regions: ['Москва', 'Московская область'],
                branding: true,
                desc: 'Сейф-пакет с клеевым клапаном.',
                specs: [
                    'courier_width_mm' => $w,
                    'courier_height_mm' => $h,
                    'courier_flap_mm' => 40,
                    'courier_thickness_mkm' => 50 + $i * 5,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0:int,1:int}>  $sizes
     * @return list<array<string, mixed>>
     */
    private function zip(string $code, string $material, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $i => [$w, $h]) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-ZIP-".($i + 1),
                category: 'zip-lock',
                title: "Zip-lock {$material} {$w}×{$h} мм",
                price: 0.8 + $i * 0.25,
                basis: 'шт',
                moq: 1000,
                stock: 'В наличии',
                prod: 5,
                deliv: 2,
                regions: ['Москва', 'Россия'],
                desc: "Пакет zip-lock, {$material}.",
                specs: [
                    'zip_width_mm' => $w,
                    'zip_height_mm' => $h,
                    'zip_thickness_mkm' => 40 + $i * 5,
                    'zip_material' => $material,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0:int,1:int}>  $sizes
     * @return list<array<string, mixed>>
     */
    private function pallets(string $code, string $material, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $i => [$l, $w]) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-PAL-".($i + 1),
                category: 'pallets',
                title: "Паллет {$material} {$l}×{$w}",
                price: 420 + $i * 80,
                basis: 'шт',
                moq: 10,
                stock: $i === 0 ? 'В наличии' : 'Под заказ',
                prod: 5 + $i,
                deliv: 3,
                regions: ['Москва', 'Московская область'],
                desc: "Паллет, {$material}.",
                specs: [
                    'pallet_material' => $material,
                    'pallet_length_mm' => $l,
                    'pallet_width_mm' => $w,
                    'pallet_dynamic_load_kg' => 1000 + $i * 250,
                ],
            );
        }
        // pad to 5 with plastic / cardboard variants so every line stays ≥3
        $out[] = $this->row(
            sku: self::SKU_PREFIX."{$code}-PAL-4",
            category: 'pallets',
            title: 'Паллет пластик 1200×800',
            price: 1450,
            basis: 'шт',
            moq: 5,
            stock: 'Под заказ',
            prod: 10,
            deliv: 4,
            regions: ['Москва'],
            desc: 'Многооборотный пластиковый паллет.',
            specs: [
                'pallet_material' => 'Пластик',
                'pallet_length_mm' => 1200,
                'pallet_width_mm' => 800,
                'pallet_dynamic_load_kg' => 1500,
            ],
        );
        $out[] = $this->row(
            sku: self::SKU_PREFIX."{$code}-PAL-5",
            category: 'pallets',
            title: 'Паллет гофрокартон 800×600',
            price: 180,
            basis: 'шт',
            moq: 20,
            stock: 'В наличии',
            prod: 4,
            deliv: 2,
            regions: ['Москва', 'ЦФО'],
            desc: 'Одноразовый картонный паллет.',
            specs: [
                'pallet_material' => 'Гофрокартон',
                'pallet_length_mm' => 800,
                'pallet_width_mm' => 600,
                'pallet_dynamic_load_kg' => 250,
            ],
        );

        return $out;
    }

    /**
     * @param  list<array{0:int,1:int,2:int}>  $sizes
     * @return list<array<string, mixed>>
     */
    private function rpc(string $code, string $format, array $sizes): array
    {
        $out = [];
        foreach ($sizes as $i => [$l, $w, $h]) {
            $out[] = $this->row(
                sku: self::SKU_PREFIX."{$code}-RPC-".($i + 1),
                category: 'rpc',
                title: "{$format} {$l}×{$w}×{$h}",
                price: 210 + $i * 40,
                basis: 'шт',
                moq: 20,
                stock: 'В наличии',
                prod: 6,
                deliv: 3,
                regions: ['Москва', 'Московская область'],
                desc: "Складская пластиковая тара, {$format}.",
                specs: [
                    'rpc_format_type' => $format,
                    'rpc_length_mm' => $l,
                    'rpc_width_mm' => $w,
                    'rpc_height_mm' => $h,
                ],
            );
        }
        $out[] = $this->row(
            sku: self::SKU_PREFIX."{$code}-RPC-4",
            category: 'rpc',
            title: 'KLT 400×300×280',
            price: 265,
            basis: 'шт',
            moq: 16,
            stock: 'В наличии',
            prod: 7,
            deliv: 3,
            regions: ['Москва'],
            desc: 'Контейнер KLT.',
            specs: [
                'rpc_format_type' => 'KLT',
                'rpc_length_mm' => 400,
                'rpc_width_mm' => 300,
                'rpc_height_mm' => 280,
            ],
        );
        $out[] = $this->row(
            sku: self::SKU_PREFIX."{$code}-RPC-5",
            category: 'rpc',
            title: 'Складной контейнер 600×400×320',
            price: 390,
            basis: 'шт',
            moq: 10,
            stock: 'Под заказ',
            prod: 12,
            deliv: 5,
            regions: ['Москва', 'ЦФО'],
            desc: 'Складной ящик.',
            specs: [
                'rpc_format_type' => 'Складной контейнер',
                'rpc_length_mm' => 600,
                'rpc_width_mm' => 400,
                'rpc_height_mm' => 320,
            ],
        );

        return $out;
    }

    /**
     * @param  list<string>  $regions
     * @param  array<string, mixed>  $specs
     * @return array<string, mixed>
     */
    private function row(
        string $sku,
        string $category,
        string $title,
        float $price,
        string $basis,
        int $moq,
        string $stock,
        int $prod,
        int $deliv,
        array $regions,
        string $desc,
        array $specs,
        bool $branding = false,
        bool $custom = false,
        bool $price_hidden = false,
    ): array {
        return [
            'sku' => $sku,
            'category' => $category,
            'title' => $title,
            'price' => $price,
            'basis' => $basis,
            'moq' => $moq,
            'stock' => $stock,
            'prod' => $prod,
            'deliv' => $deliv,
            'regions' => $regions,
            'desc' => $desc,
            'specs' => $specs,
            'branding' => $branding,
            'custom' => $custom,
            'price_hidden' => $price_hidden,
        ];
    }
}
