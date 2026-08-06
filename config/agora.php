<?php

/**
 * Схема каталога Агоры (пилот Москва/МО).
 * Источник: «Справочник характеристик упаковки» + xlsx разделы 2–5, 8.
 *
 * common  — поля оффера (колонки в offers)
 * specs   — технические поля категории (JSON offers.specs)
 * dictionaries — enum-справочники для админки
 */

return [

    'currencies' => ['RUB', 'CNY', 'USD', 'EUR'],

    // Справочники из xlsx «Агора_поля_по_категориям_backend_расширено», лист 04.
    'dictionaries' => [
        'price_basis' => ['шт', 'рулон', 'лист', 'кг', 'м', 'м2', 'м3', 'комплект', 'паллета', 'упаковка'],
        'stock_status' => ['В наличии', 'Под заказ', 'Нет в наличии', 'Ожидается'],
        'vat_rate' => ['20', '10', '0', 'Без НДС'],
        'delivery_region' => ['Москва', 'Московская область', 'ЦФО', 'Россия'],
        'payment_terms' => ['Безнал', 'Предоплата 100%', '50/50', 'Постоплата', 'Отсрочка', 'Наличные'],
        'material_general' => ['ПЭ', 'ПП', 'ПВД', 'HDPE', 'LDPE', 'BOPP', 'PVC', 'Бумага', 'Картон', 'Дерево', 'Пластик', 'Сталь', 'Алюминий'],
        'board_grade' => ['Т-21', 'Т-22', 'Т-23', 'Т-24', 'П-31', 'П-32', 'П-33'],
        'flute_profile' => ['E', 'B', 'C', 'BC', 'BE'],
        'box_type' => ['Четырёхклапанный', 'Самосборный', 'Архивный', 'Крышка-дно', 'Почтовый', 'Другой'],
        'box_ply_count' => ['3', '5', '7'],
        'liner_color' => ['Бурый', 'Белый', 'Крафт', 'Цветной'],
        'closing_type' => ['Клейкая лента', 'Самосборный замок', 'Скобы', 'Клей', 'Без закрывания'],
        'print_type' => ['Флексография', 'Офсет', 'Шелкография', 'Цифровая', 'Без печати'],
        // прочие категории (заготовка, полный V1 — по запросу Стаса)
        'adhesive_type' => ['Акрил', 'Hot melt', 'Каучук', 'Solvent'],
        'label_print_type' => ['Термо ЭКО', 'Термо ТОП', 'Термотрансфер'],
        'shrink_material' => ['ПОФ', 'ПВХ', 'ПЭ'],
        'bag_material' => ['ПВД', 'ПП', 'BOPP', 'CPP', 'Kraft/PE', 'PET/PE', 'PA/PE'],
        'tape_base_material' => ['BOPP', 'PVC', 'Бумага', 'Ткань/ПЭ'],
        'stretch_type' => ['Ручная', 'Машинная', 'Престрейч', 'Джамбо'],
        'shrink_format' => ['Полотно', 'Рукав', 'Полурукав'],
        'bubble_layers' => ['2', '3'],
        'foam_pe_form' => ['Рулон', 'Лист', 'Профиль', 'Пакет'],
        'strap_material' => ['ПП', 'ПЭТ', 'Сталь', 'Бумага', 'Корд'],
        'zip_material' => ['ПВД', 'ПП'],
        'filler_type' => ['Бумажный', 'Воздушные подушки', 'Пенополистирол', 'Крахмальный', 'Honeycomb'],
        'filler_material' => ['Бумага', 'ПЭ', 'Пенополистирол', 'Крахмал'],
        'pallet_material' => ['Дерево', 'Пластик', 'Гофрокартон', 'Металл'],
        'rpc_format_type' => ['Евроконтейнер', 'KLT', 'Складской лоток', 'Ящик с крышкой', 'Складной контейнер'],
        'sheet_format' => ['Лист', 'Рулон'],
    ],


    /**
     * Пилотные категории + схема specs-полей.
     * type: string|number|enum|boolean
     * dictionary: ключ из dictionaries (для enum)
     * unit, min, max, required
     */
    'categories' => [
        // Полный V1 + V1 optional по листу «08 Гофрокороба» (файл Стаса 06.08.2026).
        // V2 (склейка, BCT, влагостойкость…) — не подключаем до отдельного запроса.
        [
            'slug' => 'corrugated-boxes',
            'name' => 'Гофрокороба',
            'priority' => 'high',
            'sort_order' => 10,
            'schema_version' => 'boxes_v1_2026_08',
            'fields' => [
                // --- V1 обязательные ---
                ['key' => 'box_type', 'label' => 'Тип конструкции', 'type' => 'enum', 'dictionary' => 'box_type', 'required' => true, 'version' => 'v1', 'filter' => true],
                ['key' => 'box_inner_length_mm', 'label' => 'Внутренняя длина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 3000, 'required' => true, 'version' => 'v1', 'filter' => true],
                ['key' => 'box_inner_width_mm', 'label' => 'Внутренняя ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 3000, 'required' => true, 'version' => 'v1', 'filter' => true],
                ['key' => 'box_inner_height_mm', 'label' => 'Внутренняя высота', 'type' => 'number', 'unit' => 'мм', 'min' => 20, 'max' => 3000, 'required' => true, 'version' => 'v1', 'filter' => true],
                ['key' => 'box_board_grade', 'label' => 'Марка картона', 'type' => 'enum', 'dictionary' => 'board_grade', 'required' => true, 'version' => 'v1', 'filter' => true],
                ['key' => 'box_flute_profile', 'label' => 'Профиль гофры', 'type' => 'enum', 'dictionary' => 'flute_profile', 'required' => true, 'version' => 'v1', 'filter' => true],
                // --- V1 optional ---
                ['key' => 'box_fefco_code', 'label' => 'Код конструкции FEFCO', 'type' => 'string', 'required' => false, 'version' => 'v1_optional', 'filter' => true, 'hint' => '4-значный код, напр. 0201'],
                ['key' => 'box_outer_length_mm', 'label' => 'Внешняя длина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 3100, 'required' => false, 'version' => 'v1_optional'],
                ['key' => 'box_outer_width_mm', 'label' => 'Внешняя ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 3100, 'required' => false, 'version' => 'v1_optional'],
                ['key' => 'box_outer_height_mm', 'label' => 'Внешняя высота', 'type' => 'number', 'unit' => 'мм', 'min' => 20, 'max' => 3100, 'required' => false, 'version' => 'v1_optional'],
                ['key' => 'box_ply_count', 'label' => 'Количество слоёв', 'type' => 'enum', 'dictionary' => 'box_ply_count', 'required' => false, 'version' => 'v1_optional', 'filter' => true],
                ['key' => 'box_liner_color', 'label' => 'Цвет лайнера', 'type' => 'enum', 'dictionary' => 'liner_color', 'required' => false, 'version' => 'v1_optional', 'filter' => true],
                ['key' => 'box_closure_type', 'label' => 'Тип закрывания', 'type' => 'enum', 'dictionary' => 'closing_type', 'required' => false, 'version' => 'v1_optional', 'filter' => true],
                ['key' => 'box_print_available', 'label' => 'Печать доступна', 'type' => 'boolean', 'required' => false, 'version' => 'v1_optional', 'filter' => true],
            ],
        ],

        [
            'slug' => 'corrugated-sheet',
            'name' => 'Гофролист',
            'priority' => 'medium',
            'sort_order' => 20,
            'fields' => [
                ['key' => 'sheet_format', 'label' => 'Формат', 'type' => 'enum', 'dictionary' => 'sheet_format', 'required' => true],
                ['key' => 'sheet_length_mm', 'label' => 'Длина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 5000, 'required' => true],
                ['key' => 'sheet_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 5000, 'required' => true],
                ['key' => 'board_grade', 'label' => 'Марка картона', 'type' => 'enum', 'dictionary' => 'board_grade', 'required' => true],
                ['key' => 'flute_profile', 'label' => 'Профиль гофры', 'type' => 'enum', 'dictionary' => 'flute_profile', 'required' => true],
            ],
        ],
        [
            'slug' => 'stretch-film',
            'name' => 'Стрейч-пленка',
            'priority' => 'high',
            'sort_order' => 30,
            'fields' => [
                ['key' => 'stretch_type', 'label' => 'Тип намотки', 'type' => 'enum', 'dictionary' => 'stretch_type', 'required' => true],
                ['key' => 'stretch_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 100, 'max' => 1500, 'required' => true],
                ['key' => 'stretch_thickness_mkm', 'label' => 'Толщина', 'type' => 'number', 'unit' => 'мкм', 'min' => 5, 'max' => 50, 'required' => true],
                ['key' => 'stretch_length_m', 'label' => 'Длина намотки', 'type' => 'number', 'unit' => 'м', 'min' => 10, 'max' => 10000, 'required' => false],
            ],
        ],
        [
            'slug' => 'shrink-film',
            'name' => 'Термоусадочная пленка',
            'priority' => 'high',
            'sort_order' => 40,
            'fields' => [
                ['key' => 'shrink_material', 'label' => 'Материал', 'type' => 'enum', 'dictionary' => 'shrink_material', 'required' => true],
                ['key' => 'shrink_format', 'label' => 'Формат', 'type' => 'enum', 'dictionary' => 'shrink_format', 'required' => true],
                ['key' => 'shrink_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 2000, 'required' => true],
                ['key' => 'shrink_thickness_mkm', 'label' => 'Толщина', 'type' => 'number', 'unit' => 'мкм', 'min' => 8, 'max' => 200, 'required' => true],
            ],
        ],
        [
            'slug' => 'bubble-wrap',
            'name' => 'Воздушно-пузырчатая пленка',
            'priority' => 'high',
            'sort_order' => 50,
            'fields' => [
                ['key' => 'bubble_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 200, 'max' => 3000, 'required' => true],
                ['key' => 'bubble_length_m', 'label' => 'Длина', 'type' => 'number', 'unit' => 'м', 'min' => 1, 'max' => 500, 'required' => true],
                ['key' => 'bubble_layers', 'label' => 'Слои', 'type' => 'enum', 'dictionary' => 'bubble_layers', 'required' => true],
                ['key' => 'bubble_diameter_mm', 'label' => 'Диаметр пузырька', 'type' => 'number', 'unit' => 'мм', 'min' => 1, 'max' => 30, 'required' => false],
            ],
        ],
        [
            'slug' => 'foam-pe',
            'name' => 'Вспененный полиэтилен',
            'priority' => 'medium',
            'sort_order' => 60,
            'fields' => [
                ['key' => 'foam_pe_form', 'label' => 'Форма', 'type' => 'enum', 'dictionary' => 'foam_pe_form', 'required' => true],
                ['key' => 'foam_pe_thickness_mm', 'label' => 'Толщина', 'type' => 'number', 'unit' => 'мм', 'min' => 0.5, 'max' => 100, 'required' => true],
                ['key' => 'foam_pe_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 10, 'max' => 2500, 'required' => false],
                ['key' => 'foam_pe_length_m', 'label' => 'Длина / намотка', 'type' => 'number', 'unit' => 'м', 'min' => 0.1, 'max' => 1000, 'required' => false],
            ],
        ],
        [
            'slug' => 'packing-tape',
            'name' => 'Упаковочный скотч',
            'priority' => 'high',
            'sort_order' => 70,
            'fields' => [
                ['key' => 'tape_base_material', 'label' => 'Основа', 'type' => 'enum', 'dictionary' => 'tape_base_material', 'required' => true],
                ['key' => 'tape_adhesive_type', 'label' => 'Тип клея', 'type' => 'enum', 'dictionary' => 'adhesive_type', 'required' => true],
                ['key' => 'tape_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 9, 'max' => 150, 'required' => true],
                ['key' => 'tape_length_m', 'label' => 'Длина намотки', 'type' => 'number', 'unit' => 'м', 'min' => 5, 'max' => 1000, 'required' => true],
            ],
        ],
        [
            'slug' => 'strapping-tape',
            'name' => 'Стреппинг-лента',
            'priority' => 'medium',
            'sort_order' => 80,
            'fields' => [
                ['key' => 'strap_material', 'label' => 'Материал', 'type' => 'enum', 'dictionary' => 'strap_material', 'required' => true],
                ['key' => 'strap_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 5, 'max' => 32, 'required' => true],
                ['key' => 'strap_thickness_mm', 'label' => 'Толщина', 'type' => 'number', 'unit' => 'мм', 'min' => 0.3, 'max' => 1.5, 'required' => true],
                ['key' => 'strap_length_m', 'label' => 'Длина намотки', 'type' => 'number', 'unit' => 'м', 'min' => 100, 'max' => 10000, 'required' => true],
            ],
        ],
        [
            'slug' => 'courier-bags',
            'name' => 'Курьерские и сейф-пакеты',
            'priority' => 'high',
            'sort_order' => 90,
            'fields' => [
                ['key' => 'courier_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 80, 'max' => 1000, 'required' => true],
                ['key' => 'courier_height_mm', 'label' => 'Высота', 'type' => 'number', 'unit' => 'мм', 'min' => 80, 'max' => 1200, 'required' => true],
                ['key' => 'courier_flap_mm', 'label' => 'Клапан', 'type' => 'number', 'unit' => 'мм', 'min' => 0, 'max' => 120, 'required' => false],
                ['key' => 'courier_thickness_mkm', 'label' => 'Толщина пленки', 'type' => 'number', 'unit' => 'мкм', 'min' => 30, 'max' => 150, 'required' => true],
            ],
        ],
        [
            'slug' => 'zip-lock',
            'name' => 'Zip-lock пакеты',
            'priority' => 'medium',
            'sort_order' => 100,
            'fields' => [
                ['key' => 'zip_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 30, 'max' => 1000, 'required' => true],
                ['key' => 'zip_height_mm', 'label' => 'Высота', 'type' => 'number', 'unit' => 'мм', 'min' => 30, 'max' => 1000, 'required' => true],
                ['key' => 'zip_thickness_mkm', 'label' => 'Толщина', 'type' => 'number', 'unit' => 'мкм', 'min' => 20, 'max' => 200, 'required' => true],
                ['key' => 'zip_material', 'label' => 'Материал', 'type' => 'enum', 'dictionary' => 'zip_material', 'required' => true],
            ],
        ],
        [
            'slug' => 'fillers',
            'name' => 'Наполнители',
            'priority' => 'medium',
            'sort_order' => 110,
            'fields' => [
                ['key' => 'filler_type', 'label' => 'Тип наполнителя', 'type' => 'enum', 'dictionary' => 'filler_type', 'required' => true],
                ['key' => 'filler_material', 'label' => 'Материал', 'type' => 'enum', 'dictionary' => 'filler_material', 'required' => true],
                ['key' => 'filler_pack_volume_l', 'label' => 'Объем упаковки', 'type' => 'number', 'unit' => 'л', 'min' => 1, 'max' => 1000, 'required' => false],
                ['key' => 'filler_pack_weight_kg', 'label' => 'Вес упаковки', 'type' => 'number', 'unit' => 'кг', 'min' => 0.05, 'max' => 100, 'required' => false],
            ],
        ],
        [
            'slug' => 'thermal-labels',
            'name' => 'Термоэтикетки',
            'priority' => 'high',
            'sort_order' => 120,
            'fields' => [
                ['key' => 'label_print_type', 'label' => 'Тип печати', 'type' => 'enum', 'dictionary' => 'label_print_type', 'required' => true],
                ['key' => 'label_width_mm', 'label' => 'Ширина этикетки', 'type' => 'number', 'unit' => 'мм', 'min' => 10, 'max' => 200, 'required' => true],
                ['key' => 'label_height_mm', 'label' => 'Высота этикетки', 'type' => 'number', 'unit' => 'мм', 'min' => 10, 'max' => 300, 'required' => true],
                ['key' => 'labels_per_roll', 'label' => 'Этикеток в рулоне', 'type' => 'number', 'unit' => 'шт', 'min' => 50, 'max' => 10000, 'required' => true],
            ],
        ],
        [
            'slug' => 'pallets',
            'name' => 'Паллеты',
            'priority' => 'high',
            'sort_order' => 130,
            'fields' => [
                ['key' => 'pallet_material', 'label' => 'Материал', 'type' => 'enum', 'dictionary' => 'pallet_material', 'required' => true],
                ['key' => 'pallet_length_mm', 'label' => 'Длина', 'type' => 'number', 'unit' => 'мм', 'min' => 400, 'max' => 2000, 'required' => true],
                ['key' => 'pallet_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 300, 'max' => 2000, 'required' => true],
                ['key' => 'pallet_dynamic_load_kg', 'label' => 'Динамическая нагрузка', 'type' => 'number', 'unit' => 'кг', 'min' => 50, 'max' => 5000, 'required' => false],
            ],
        ],
        [
            'slug' => 'rpc',
            'name' => 'Складская пластиковая тара',
            'priority' => 'medium',
            'sort_order' => 140,
            'fields' => [
                ['key' => 'rpc_format_type', 'label' => 'Формат', 'type' => 'enum', 'dictionary' => 'rpc_format_type', 'required' => true],
                ['key' => 'rpc_length_mm', 'label' => 'Длина', 'type' => 'number', 'unit' => 'мм', 'min' => 100, 'max' => 1200, 'required' => true],
                ['key' => 'rpc_width_mm', 'label' => 'Ширина', 'type' => 'number', 'unit' => 'мм', 'min' => 100, 'max' => 1000, 'required' => true],
                ['key' => 'rpc_height_mm', 'label' => 'Высота', 'type' => 'number', 'unit' => 'мм', 'min' => 50, 'max' => 1200, 'required' => true],
            ],
        ],
    ],
];
