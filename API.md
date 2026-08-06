# Agora Public API — документация для фронтенда

Документ для команды **product frontend**  
репозиторий: [paulzverev/agora](https://github.com/paulzverev/agora) (Next.js / Vercel).

API **только чтение** (GET), **без авторизации**.  
Админка (write) — отдельный SPA, в этом документе **не** описана.

---

## Base URL

| Среда | URL |
|---|---|
| **Production** | `https://agora.178.88.115.213.sslip.io` |
| API prefix | `/api` |

Полный префикс запросов:

```text
https://agora.178.88.115.213.sslip.io/api
```

Health-check (не JSON, HTML «Application up»):

```http
GET https://agora.178.88.115.213.sslip.io/up
```

---

## Общие правила

| | |
|---|---|
| Формат | JSON, UTF-8 |
| Auth | **нет** (публичная витрина) |
| CORS | origin `https://agora-trade.vercel.app` + preview `agora-*.vercel.app` |
| Кириллица | может быть `\u041c...` в raw JSON — `response.json()` разворачивает сам |
| Только active | inactive поставщики/офферы **не** отдаются (404 на show) |
| Пагинация | Laravel: `data` + `meta` + `links` |
| Ошибки | `404` / `422` / `500` — body JSON с `message` где применимо |

### Headers (рекомендуется)

```http
Accept: application/json
```

### Env на фронте (пример)

```env
NEXT_PUBLIC_API_URL=https://agora.178.88.115.213.sslip.io/api
```

---

## Карта эндпоинтов

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/categories` | Список категорий (фильтры, меню) |
| GET | `/api/suppliers` | Список активных поставщиков |
| GET | `/api/suppliers/{id}` | Карточка поставщика |
| GET | `/api/offers` | Каталог офферов (SKU) — **основной для сравнения** |
| GET | `/api/offers/{id}` | Карточка оффера |
| GET | `/files/{path}` | Отдача файла (лого / фото) — **не** вызывается руками, URL уже в JSON |

---

## 0. Логотипы и фото (обязательно прочитать)

Отдельного «logo API» **нет**. Картинки приходят **готовыми HTTPS-ссылками** в JSON.  
Загрузка файлов — только через **админку**. Витрина **только читает**.

### Какие поля

| Поле | Где | Что это |
|---|---|---|
| `logo_url` | `GET /api/suppliers`, `GET /api/suppliers/{id}` | логотип **компании-поставщика** |
| `supplier.logo_url` | `GET /api/offers`, `GET /api/offers/{id}` | то же лого, уже внутри оффера |
| `photo_url` | `GET /api/offers`, `GET /api/offers/{id}` | **главное фото оффера** (товар) |

Тип: `string | null`.

- есть файл → полный URL, например  
  `https://agora.178.88.115.213.sslip.io/files/logos/Ab12cd.webp`
- нет файла → **`null`** (на UI нужен **плейсхолдер**, не битая картинка)

### Как отдаётся файл

URL строится бэкендом как:

```text
{APP_URL}/files/{relative_path}
```

Примеры путей:

```text
/files/logos/xxxx.webp     ← лого поставщика
/files/offers/yyyy.jpg     ← фото оффера
```

Фронту **не нужно** собирать путь самому и **не нужно** ходить на `/files/...` без `logo_url`/`photo_url` из JSON.  
Просто:

```tsx
// лого поставщика (список / карточка supplier)
{supplier.logo_url ? (
  <img src={supplier.logo_url} alt={supplier.commercial_name} />
) : (
  <div className="placeholder">{/* инициалы / иконка */}</div>
)}

// лого в карточке оффера
{offer.supplier?.logo_url ? (
  <img
    src={offer.supplier.logo_url}
    alt={offer.supplier.commercial_name}
  />
) : null}

// фото товара
{offer.photo_url ? (
  <img src={offer.photo_url} alt={offer.offer_title} />
) : (
  <div className="placeholder">Нет фото</div>
)}
```

### Важные детали для Next.js

1. **`logo_url` / `photo_url` уже абсолютные** (`https://…`). Не префиксируй `NEXT_PUBLIC_API_URL`.
2. Если используешь `next/image`, добавь хост API в `next.config`:

```js
// next.config.js / next.config.mjs
images: {
  remotePatterns: [
    {
      protocol: 'https',
      hostname: 'agora.178.88.115.213.sslip.io',
      pathname: '/files/**',
    },
  ],
},
```

   Либо используй обычный `<img>` — проще, без конфига.

3. **CORS на `/files/*`**: картинки в `<img src>` не требуют CORS.  
   Проблемы CORS бывают только у `fetch` к `/api/*` с чужого origin.
4. Форматы с бэка: **PNG, JPG, WebP** (лого и фото). Размер файла до ~5 МБ (лого) / ~10 МБ (фото оффера) — на витрине это уже готовые URL.
5. **Не** жди base64, blob-id или отдельного `GET /api/logos/{id}` — такого контракта нет.
6. Кэш: URL стабильный, пока файл не заменят в админке (при замене путь обычно новый).

### Мини-пример ответа оффера (фрагмент)

```json
{
  "data": {
    "id": 12,
    "offer_title": "Гофрокороб Т-23 B 400x300x200",
    "photo_url": "https://agora.178.88.115.213.sslip.io/files/offers/abc.webp",
    "supplier": {
      "id": 1,
      "commercial_name": "ПакПоставка",
      "logo_url": "https://agora.178.88.115.213.sslip.io/files/logos/xyz.webp"
    }
  }
}
```

Если `photo_url` или `logo_url` = `null` — в админке просто ещё не загрузили файл.

---

## 1. Категории

```http
GET /api/categories
```

Без query-параметров. Только `is_active = true`, сортировка `sort_order`.

### Ответ `200`

```json
{
  "data": [
    {
      "id": 1,
      "slug": "corrugated-boxes",
      "name": "Гофрокороба",
      "priority": "high",
      "sort_order": 10
    },
    {
      "id": 3,
      "slug": "stretch-film",
      "name": "Стрейч-пленка",
      "priority": "high",
      "sort_order": 30
    }
  ]
}
```

### Пилотные slug (справочно)

| slug | Название |
|---|---|
| `corrugated-boxes` | Гофрокороба |
| `corrugated-sheet` | Гофролист |
| `stretch-film` | Стрейч-пленка |
| `shrink-film` | Термоусадочная пленка |
| `bubble-wrap` | Воздушно-пузырчатая пленка |
| `foam-pe` | Вспененный полиэтилен |
| `packing-tape` | Упаковочный скотч |
| `strapping-tape` | Стреппинг-лента |
| `courier-bags` | Курьерские и сейф-пакеты |
| `zip-lock` | Zip-lock пакеты |
| `fillers` | Наполнители |
| `thermal-labels` | Термоэтикетки |
| `pallets` | Паллеты |
| `rpc` | Складская пластиковая тара |

Для фильтров каталога используй `slug` → `GET /api/offers?category=stretch-film`.

---

## 2. Поставщики

### 2.1. Список

```http
GET /api/suppliers
```

Только **активные**, сортировка по `commercial_name` ASC.

#### Query

| Параметр | Тип | Default | Описание |
|---|---|---|---|
| `q` | string | — | Поиск по commercial_name / legal_name (LIKE) |
| `city` | string | — | Точное совпадение города отгрузки |
| `per_page` | int | `20` | 1…100 |
| `page` | int | `1` | Номер страницы |

```http
GET /api/suppliers?q=паллет&city=Москва&per_page=20&page=1
```

#### Ответ `200`

```json
{
  "data": [
    {
      "id": 1,
      "commercial_name": "ПакПоставка",
      "legal_name": "ООО ПакПоставка",
      "inn": "7707083893",
      "legal_address": "г. Москва, …",
      "logo_url": "https://agora.178.88.115.213.sslip.io/files/logos/xxx.png",
      "contact": {
        "person": "Иван Иванов",
        "phone": "+7 999 000-00-00",
        "email": "sales@example.com",
        "website": "https://example.com",
        "telegram": "@pack"
      },
      "shipping_cities": ["Москва", "Московская область"]
    }
  ],
  "links": {
    "first": "…/api/suppliers?page=1",
    "last": "…/api/suppliers?page=3",
    "prev": null,
    "next": "…/api/suppliers?page=2"
  },
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 42
  }
}
```

### 2.2. Один поставщик

```http
GET /api/suppliers/{id}
```

| Код | Когда |
|---|---|
| `200` | найден и active |
| `404` | нет id **или** inactive |

```json
{
  "data": {
    "id": 1,
    "commercial_name": "ПакПоставка",
    "legal_name": "ООО ПакПоставка",
    "inn": "7707083893",
    "legal_address": "…",
    "logo_url": "https://…/files/logos/….png",
    "contact": {
      "person": "…",
      "phone": "…",
      "email": "…",
      "website": "…",
      "telegram": null
    },
    "shipping_cities": ["Москва"]
  }
}
```

### Поля поставщика

| Поле | Тип | Nullable | Описание |
|---|---|---|---|
| `id` | number | нет | ID |
| `commercial_name` | string | нет | Коммерческое название |
| `legal_name` | string | да | Юр. название |
| `inn` | string | нет | ИНН 10/12 |
| `legal_address` | string | да | Адрес |
| `logo_url` | string | да | Абсолютный URL логотипа |
| `contact.person` | string | да | Контакт |
| `contact.phone` | string | да | Телефон |
| `contact.email` | string | да | Email |
| `contact.website` | string | да | Сайт |
| `contact.telegram` | string | да | Telegram |
| `shipping_cities` | string[] | нет* | Города отгрузки (`[]` если нет) |

\* массив всегда присутствует при `with cities`, может быть пустым.

---

## 3. Офферы (SKU) — главный каталог

Оффер = предложение поставщика по конкретному товару упаковки  
(цена, MOQ, наличие, регион, тех. характеристики).

### 3.1. Список

```http
GET /api/offers
```

Только **опубликованные** (`is_active`), сортировка по **`price_value` ASC** (дешёвые сверху).

#### Query

| Параметр | Тип | Default | Описание |
|---|---|---|---|
| `q` | string | — | Поиск по `offer_title` |
| `category` | string | — | **slug** категории (`stretch-film`) |
| `category_id` | int | — | id категории |
| `supplier_id` | int | — | id поставщика |
| `stock_status` | string | — | точное значение, см. ниже |
| `region` | string | — | регион из `delivery_regions` (JSON contains) |
| `per_page` | int | `20` | 1…100 |
| `page` | int | `1` | страница |

Примеры:

```http
GET /api/offers?category=corrugated-boxes&region=Москва&per_page=24
GET /api/offers?q=стрейч&stock_status=В%20наличии
GET /api/offers?supplier_id=1&category_id=3
```

#### `stock_status` (допустимые значения)

- `В наличии`
- `Под заказ`
- `Нет в наличии`
- `Ожидается`

#### `region` (допустимые в пилоте)

- `Москва`
- `Московская область`
- `ЦФО`
- `Россия`

#### Ответ `200`

```json
{
  "data": [
    {
      "id": 12,
      "offer_title": "Гофрокороб Т-23 B 400x300x200",
      "supplier": {
        "id": 1,
        "commercial_name": "ПакПоставка",
        "logo_url": "https://…/files/logos/….png"
      },
      "category": {
        "id": 1,
        "slug": "corrugated-boxes",
        "name": "Гофрокороба"
      },
      "price_value": 18.5,
      "currency": "RUB",
      "price_basis": "шт",
      "moq_value": 100,
      "stock_status": "В наличии",
      "production_lead_days": 5,
      "delivery_lead_days": 2,
      "delivery_regions": ["Москва", "Московская область"],
      "pickup_available": true,
      "payment_terms": "Безнал",
      "vat_rate": "20",
      "branding_available": false,
      "photo_url": "https://…/files/offers/….jpg",
      "description_short": "Подходит для маркетплейсов…",
      "specs": {
        "box_type": "Четырехклапанный",
        "inner_length_mm": 400,
        "inner_width_mm": 300,
        "inner_height_mm": 200,
        "board_grade": "Т-23",
        "flute_profile": "B"
      }
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

### 3.2. Один оффер

```http
GET /api/offers/{id}
```

| Код | Когда |
|---|---|
| `200` | найден и active |
| `404` | нет / inactive |

Объект в `data` — тот же shape, что элемент списка.

---

## 4. Поля оффера (для UI и сравнения)

### Коммерческие (общие для всех категорий)

| Поле | Тип | Nullable | UI / зачем |
|---|---|---|---|
| `id` | number | нет | ключ, ссылка `/offers/[id]` |
| `offer_title` | string | нет | заголовок карточки |
| `supplier` | object | нет* | блок поставщика |
| `supplier.id` | number | | ссылка на поставщика |
| `supplier.commercial_name` | string | | имя |
| `supplier.logo_url` | string\|null | | лого |
| `category` | object | нет* | бейдж категории |
| `category.id` | number | | |
| `category.slug` | string | | фильтр / роут |
| `category.name` | string | | подпись RU |
| `price_value` | number | нет | **главный** критерий сравнения |
| `currency` | string | нет | `RUB` \| `CNY` \| `USD` \| `EUR` |
| `price_basis` | string | нет | ед. продажи: `шт`, `рулон`, `лист`, `кг`, `м`, `м2`, `м3`, `комплект`, `паллета`, `упаковка` |
| `moq_value` | number | нет | мин. партия |
| `stock_status` | string | нет | наличие |
| `production_lead_days` | number\|null | да | срок пр-ва, дни |
| `delivery_lead_days` | number\|null | да | срок доставки, дни |
| `delivery_regions` | string[] | нет | регионы поставки |
| `pickup_available` | boolean | нет | самовывоз |
| `payment_terms` | string | нет | условия оплаты |
| `vat_rate` | string | нет | `20` \| `10` \| `0` \| `Без НДС` |
| `branding_available` | boolean | нет | брендирование |
| `photo_url` | string\|null | да | главное фото |
| `description_short` | string\|null | да | описание |
| `specs` | object | нет | тех. поля категории (ключ→значение) |

\* в list/show всегда подгружаются `supplier` и `category`.

### `specs` — динамика по категории

Ключи зависят от `category.slug`. Неизвестные ключи не показывай;  
для UI используй **human labels** на фронте (маппинг) или выводи `key: value`.

Примеры:

**Гофрокороба** (`corrugated-boxes`):

| key | пример |
|---|---|
| `box_type` | `Четырехклапанный` |
| `inner_length_mm` | `400` |
| `inner_width_mm` | `300` |
| `inner_height_mm` | `200` |
| `board_grade` | `Т-23` |
| `flute_profile` | `B` |

**Стрейч-пленка** (`stretch-film`):

| key | пример |
|---|---|
| `stretch_type` | `Ручная` |
| `stretch_width_mm` | `500` |
| `stretch_thickness_mkm` | `20` |
| `stretch_length_m` | `300` |

**Паллеты** (`pallets`):

| key | пример |
|---|---|
| `pallet_material` | `Дерево` |
| `pallet_length_mm` | `1200` |
| `pallet_width_mm` | `800` |
| `pallet_dynamic_load_kg` | `1500` |

Числа в `specs` могут приходить как number **или** string — нормализуй: `Number(x)`.

---

## 5. Пагинация (Laravel)

Всегда смотри `meta`:

```ts
type PageMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  // также могут быть from, to, path, links[]
}
```

- Следующая страница: `?page={current_page + 1}` пока `current_page < last_page`
- `links.next` / `links.prev` — готовые URL или `null`

---

## 6. TypeScript-типы (копипаст)

```ts
export type Supplier = {
  id: number
  commercial_name: string
  legal_name: string | null
  inn: string
  legal_address: string | null
  logo_url: string | null
  contact: {
    person: string | null
    phone: string | null
    email: string | null
    website: string | null
    telegram: string | null
  }
  shipping_cities: string[]
}

export type Category = {
  id: number
  slug: string
  name: string
  priority: string
  sort_order: number
}

export type Offer = {
  id: number
  offer_title: string
  supplier: {
    id: number
    commercial_name: string
    logo_url: string | null
  }
  category: {
    id: number
    slug: string
    name: string
  }
  price_value: number
  currency: string
  price_basis: string
  moq_value: number
  stock_status: string
  production_lead_days: number | null
  delivery_lead_days: number | null
  delivery_regions: string[]
  pickup_available: boolean
  payment_terms: string
  vat_rate: string
  branding_available: boolean
  photo_url: string | null
  description_short: string | null
  specs: Record<string, string | number | boolean>
}

export type Paginated<T> = {
  data: T[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}
```

---

## 7. Примеры (Next.js / fetch)

```ts
const API = process.env.NEXT_PUBLIC_API_URL! // …/api

async function getJson<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API}${path}`, {
    ...init,
    headers: { Accept: 'application/json', ...(init?.headers || {}) },
    // next: { revalidate: 60 }, // ISR при желании
  })
  if (!res.ok) {
    throw new Error(`API ${res.status}: ${path}`)
  }
  return res.json() as Promise<T>
}

// Категории для сайдбара
const { data: categories } = await getJson<{ data: Category[] }>('/categories')

// Каталог: гофрокороба, Москва, страница 1
const offersPage = await getJson<Paginated<Offer>>(
  `/offers?category=corrugated-boxes&region=${encodeURIComponent('Москва')}&per_page=24&page=1`,
)

// Карточка
const { data: offer } = await getJson<{ data: Offer }>('/offers/12')

// Поставщики
const suppliersPage = await getJson<Paginated<Supplier>>('/suppliers?per_page=50')

// Картинки — только из полей ответа (см. раздел 0)
// offer.photo_url, offer.supplier.logo_url, supplier.logo_url
```

### Сравнение офферов (логика витрины)

Рекомендуемые колонки таблицы сравнения (из доков продукта):

1. `price_value` + `currency` + `price_basis` (учти `price_hidden`: цена может быть `null`)
2. `moq_value` + `order_step`
3. `stock_status`
4. `delivery_regions`
5. `delivery_lead_days`
6. размеры / материал из `specs` (зависят от категории)
7. `branding_available`
8. `supplier.commercial_name` + `supplier.logo_url` (аватар в строке сравнения)


---

## 8. Ошибки

| HTTP | Когда | Что делать на UI |
|---|---|---|
| `200` | ок | рендер |
| `404` | нет сущности / inactive | empty state / not found page |
| `422` | на public почти не бывает | — |
| `500` | сбой сервера | toast «попробуйте позже» |
| network | CORS / offline | проверить `NEXT_PUBLIC_API_URL` и CORS |

Пример 404:

```json
{
  "message": "No query results for model [App\\Models\\Offer] 999"
}
```

или Laravel abort message.

---

## 9. CORS / домены

Разрешены:

- production frontend: `FRONTEND_URL` (сейчас `https://agora-trade.vercel.app`)
- preview: pattern `https://agora-*.vercel.app`
- localhost для дева

Если новый домен фронта — напишите бэку, добавим в `FRONTEND_URL`.

Запросы с **другого** origin без CORS → браузер заблокирует (это не баг API).

---

## 10. Что API **не** делает (пока)

- ❌ регистрация / login покупателя  
- ❌ корзина / заказ / оплата  
- ❌ write с витрины  
- ❌ WebSocket / realtime  
- ❌ GraphQL  

Каталог = read-only REST.

---

## 11. Быстрый smoke-test

```bash
curl -sS "https://agora.178.88.115.213.sslip.io/api/categories" | head
curl -sS "https://agora.178.88.115.213.sslip.io/api/offers?per_page=5"
curl -sS "https://agora.178.88.115.213.sslip.io/api/suppliers?per_page=5"
curl -sS -o /dev/null -w "%{http_code}\n" "https://agora.178.88.115.213.sslip.io/up"
```

---

## 12. Контакты / репозитории

| | |
|---|---|
| Backend repo | https://github.com/Rauan228/agora-backend |
| Frontend repo | https://github.com/paulzverev/agora |
| Этот файл | `API.md` в backend-репо |
| Вопросы по контракту | backend team (Rauan) |

При breaking changes бэкенд обновляет этот файл и пишет в changelog PR.

---

*Последнее обновление: 2026-08 — prod URL VPS, offers + categories, logo_url/photo_url для витрины, публичный read-only API.*

