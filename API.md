# Agora API — документация для фронтенда

Публичное REST API бэкенда Agora. Отдаёт данные поставщиков для фронта
(Next.js) вместо захардкоженных моков. Только чтение (GET), без авторизации.

**Базовый URL (прод):**
```
https://web-production-78053c.up.railway.app
```

Все ответы — в формате JSON (UTF-8). Кириллица в ответах приходит
в экранированном виде (`М...`) — это нормально, любой JSON-парсер
(`response.json()`, `JSON.parse`) разворачивает её автоматически.

---

## Эндпоинты

### 1. Список поставщиков

```
GET /api/suppliers
```

Возвращает только **активных** поставщиков, отсортированных по названию,
с пагинацией.

**Параметры запроса (все необязательные):**

| Параметр   | Тип    | Описание                                                | Пример          |
|------------|--------|---------------------------------------------------------|-----------------|
| `q`        | string | Поиск по названию (коммерческому и юридическому)         | `?q=паллет`     |
| `city`     | string | Фильтр по городу отгрузки (точное название)              | `?city=Москва`  |
| `per_page` | int    | Размер страницы, 1–100 (по умолчанию 20)                | `?per_page=50`  |
| `page`     | int    | Номер страницы                                          | `?page=2`       |

Параметры можно комбинировать: `?q=упак&city=Москва&per_page=10`

**Пример ответа:**
```json
{
  "data": [
    {
      "id": 2,
      "commercial_name": "второй",
      "legal_name": "юрвторой",
      "inn": "500100732259",
      "legal_address": "adress",
      "logo_url": "https://web-production-78053c.up.railway.app/files/logos/Tfa...png",
      "contact": {
        "person": "Rauan Akhmetov",
        "phone": "+7 775 839 3464",
        "email": "rauan@progon.pro",
        "website": "https://example.com",
        "telegram": null
      },
      "shipping_cities": ["Москва"]
    }
  ],
  "links": {
    "first": ".../api/suppliers?page=1",
    "last":  ".../api/suppliers?page=1",
    "prev":  null,
    "next":  null
  },
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 2
  }
}
```

> Список поставщиков лежит в `data`. Информация о пагинации — в `meta`
> (`current_page`, `last_page`, `total`) и `links` (`next`/`prev` — null,
> если страницы нет).

---

### 2. Один поставщик

```
GET /api/suppliers/{id}
```

Возвращает одного активного поставщика по его `id`.

- **200** — поставщик найден (объект в `data`).
- **404** — поставщик не найден или неактивен.

**Пример ответа:**
```json
{
  "data": {
    "id": 1,
    "commercial_name": "Тест",
    "legal_name": "Тестюр",
    "inn": "7707083893",
    "legal_address": "Kumisbekova",
    "logo_url": "https://web-production-78053c.up.railway.app/files/logos/C0S...png",
    "contact": {
      "person": "Rauan Akhmetov",
      "phone": "87758393464",
      "email": "admin@agora.com",
      "website": "https://example.com",
      "telegram": null
    },
    "shipping_cities": ["Astana"]
  }
}
```

---

## Описание полей поставщика

| Поле               | Тип             | Описание                                              |
|--------------------|-----------------|------------------------------------------------------|
| `id`               | number          | Идентификатор поставщика                              |
| `commercial_name`  | string          | Коммерческое название компании                       |
| `legal_name`       | string \| null  | Юридическое название                                  |
| `inn`              | string          | ИНН                                                  |
| `legal_address`    | string \| null  | Фактический адрес регистрации                        |
| `logo_url`         | string \| null  | Полная ссылка на логотип (можно вставлять в `<img>`) |
| `contact.person`   | string \| null  | Контактное лицо                                      |
| `contact.phone`    | string \| null  | Телефон                                              |
| `contact.email`    | string \| null  | Email                                                |
| `contact.website`  | string \| null  | Сайт                                                 |
| `contact.telegram` | string \| null  | Telegram                                             |
| `shipping_cities`  | string[]        | Города отгрузки                                       |

> Любое поле, кроме `id`, `commercial_name`, `inn` и `shipping_cities`,
> может быть `null` — на фронте стоит это учитывать.

---

## Примеры использования (JavaScript / fetch)

```js
const API = "https://web-production-78053c.up.railway.app/api";

// Список поставщиков
const res = await fetch(`${API}/suppliers`);
const { data, meta } = await res.json();
console.log(data);          // массив поставщиков
console.log(meta.total);    // всего записей

// Поиск + фильтр по городу
const res2 = await fetch(`${API}/suppliers?q=упак&city=Москва`);
const { data: found } = await res2.json();

// Один поставщик
const res3 = await fetch(`${API}/suppliers/1`);
if (res3.ok) {
  const { data: supplier } = await res3.json();
}

// Логотип — logo_url можно вставлять напрямую
// <img src={supplier.logo_url} alt={supplier.commercial_name} />
```

---

### 3. Список офферов (SKU)

```
GET /api/offers
```

Только **опубликованные** (`is_active`). Параметры: `q`, `category` (slug),
`category_id`, `supplier_id`, `stock_status`, `region`, `per_page`, `page`.

### 4. Один оффер

```
GET /api/offers/{id}
```

---

## Admin API (React SPA)

Префикс `/api/admin/*`. Auth: **Bearer token** (Laravel Sanctum).

| Метод | Путь | Описание |
|---|---|---|
| POST | `/api/admin/login` | `{ email, password }` → `{ token, user }` |
| POST | `/api/admin/logout` | отозвать токен |
| GET | `/api/admin/me` | текущий пользователь |
| GET | `/api/admin/meta/categories` | категории + схема specs-полей |
| GET | `/api/admin/meta/dictionaries` | enum-справочники |
| GET/POST/… | `/api/admin/suppliers` | CRUD поставщиков |
| GET/POST/… | `/api/admin/offers` | CRUD офферов |

Пример логина:

```js
const res = await fetch(`${API}/admin/login`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  body: JSON.stringify({ email: 'admin@agora.local', password: 'password' }),
});
const { token } = await res.json();
// дальше: Authorization: Bearer ${token}
```

Мультипарт (логотип/фото): `POST /api/admin/suppliers` и
`POST /api/admin/offers` (update тоже через POST — удобнее для FormData).

Схема тех. полей оффера зависит от категории (`config/agora.php`,
документы фаундера: общие поля + specs по категории).

---

## Примечания

- **CORS**: `FRONTEND_URL` + `ADMIN_FRONTEND_URL` (через запятую),
  плюс превью `agora-*.vercel.app` / `agora-admin*.vercel.app` и localhost.
- Публичное API отдаёт **только активных** поставщиков и офферов.
- Админка: React SPA в `admin/` (Vercel). Legacy Blade `/admin` пока есть.
