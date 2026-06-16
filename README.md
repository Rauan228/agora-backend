# Agora — Backend (Laravel)

Бэкенд и админка для B2B-маркетплейса поставщиков транспортной упаковки **Agora**.

- **Фронт** (Next.js): https://agora-trade.vercel.app — [репозиторий](https://github.com/paulzverev/agora)
- **Бэк** (этот репозиторий): Laravel 13 + PostgreSQL, деплой на Railway
- **Админка**: голый Blade внутри этого же приложения (`/admin`)

## Стек

| Компонент | Технология |
|---|---|
| PHP | 8.3+ |
| Фреймворк | Laravel 13 |
| БД | PostgreSQL (прод, Railway). Локально допустимо SQLite |
| Админка | Blade + контроллеры, сессионная аутентификация |
| API | REST, JSON (`/api/*`), CORS под фронт |

## Быстрый старт

```bash
# 1. Зависимости
composer install

# 2. Окружение
cp .env.example .env
php artisan key:generate

# 3. БД
#   - для локальной разработки без сети оставь DB_CONNECTION=sqlite
#   - для Postgres (Railway) раскомментируй блок pgsql в .env и заполни данные
php artisan migrate --seed

# 4. Линк для логотипов (один раз)
php artisan storage:link

# 5. Запуск
php artisan serve
```

После сидирования создаётся админ:
**email:** `admin@agora.local` · **пароль:** `password` (смени на проде!)

Админка: http://localhost:8000/admin

## Структура БД

### `suppliers` — поставщики (компании)

| Поле | Тип | Поле аналитика |
|---|---|---|
| `commercial_name` | string | Коммерческое название компании |
| `legal_name` | string, nullable | Юридическое название компании |
| `inn` | string, unique | ИНН (10/12 цифр, валидация контрольной суммы) |
| `legal_address` | string, nullable | Фактический адрес регистрации |
| `logo_path` | string, nullable | Логотип (файл в storage) |
| `contact_person`, `phone`, `email`, `website` | string, nullable | Контактные данные |
| `is_active` | bool | Показывать на фронте |

### `cities` + `city_supplier`

Города отгрузки вынесены в справочник `cities` и связаны с поставщиками
many-to-many через `city_supplier`. Один поставщик отгружает из многих городов.

> Поля заданы аналитиком и **будут меняться** — структура расширяемая,
> новые поля добавляются отдельными миграциями.

## REST API (для фронта)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/api/suppliers` | Активные поставщики. Параметры: `?q=` (поиск), `?city=` (фильтр), пагинация |
| GET | `/api/suppliers/{id}` | Один поставщик |

Формат ответа — см. `app/Http/Resources/SupplierResource.php`.

CORS управляется переменной `FRONTEND_URL` в `.env` (origin фронта; превью-деплои
Vercel `agora-*.vercel.app` разрешены автоматически).

## Деплой на Railway

1. Создать проект на Railway, добавить **PostgreSQL** и сервис из этого репозитория.
2. Переменные окружения сервиса (Variables):
   - `APP_KEY` (`php artisan key:generate --show`)
   - `APP_ENV=production`, `APP_DEBUG=false`
   - `DB_CONNECTION=pgsql` и данные Postgres (Railway подставляет их через `${{Postgres.*}}`)
   - `FRONTEND_URL=https://agora-trade.vercel.app`
3. Команда запуска — см. `Procfile`.

pgAdmin подключается к Railway Postgres по публичным host/port/database/user/password
из вкладки **Variables** сервиса Postgres.
