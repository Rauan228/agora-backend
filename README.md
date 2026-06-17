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

## Деплой на Railway (Nixpacks)

Railway деплоит из GitHub-репозитория. Сборка — через Nixpacks (без Docker):
PHP-провайдер сам поднимает **nginx + php-fpm**. Сборка и миграции описаны
в `nixpacks.toml`.

1. Запушить этот репозиторий на GitHub.
2. На [railway.app](https://railway.app): New Project → Deploy from GitHub repo → выбрать репо.
3. В проект добавить **PostgreSQL** (New → Database → PostgreSQL).
4. В сервисе приложения → **Variables** задать:
   ```
   APP_KEY=base64:...            # php artisan key:generate --show
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://<твой-домен>.up.railway.app   # ВАЖНО: для корректных URL логотипов
   NIXPACKS_PHP_ROOT_DIR=/app/public   # веб-корень = public/

   ADMIN_EMAIL=admin@agora.com         # из них AdminSeeder создаёт админа
   ADMIN_PASSWORD=...

   DB_CONNECTION=pgsql
   DB_HOST=${{Postgres.PGHOST}}
   DB_PORT=${{Postgres.PGPORT}}
   DB_DATABASE=${{Postgres.PGDATABASE}}
   DB_USERNAME=${{Postgres.PGUSER}}
   DB_PASSWORD=${{Postgres.PGPASSWORD}}

   FRONTEND_URL=https://agora-trade.vercel.app
   ```
   `${{Postgres.*}}` — ссылки на переменные сервиса Postgres (Railway подставит сам).
5. Сгенерировать домен: Settings → Networking → Generate Domain.
   Скопировать его в `APP_URL`.

### Хранение логотипов (Railway Volume)

Файловая система контейнера эфемерна — загруженные логотипы пропали бы при каждом
деплое. Поэтому подключаем **постоянный диск (Volume)**:

1. В сервисе приложения → **Settings → Volumes → New Volume**.
2. Mount path: **`/app/storage/app/public`** (туда пишутся логотипы).
3. Готово. Симлинк `public/storage` создаётся автоматически в `preDeployCommand`
   (`php artisan storage:link --force`), URL логотипов берётся из `APP_URL`.

Миграции, сид админа и `storage:link` выполняются в `preDeployCommand` (`railway.json`)
при каждом деплое — в рантайме, когда БД и Volume уже доступны.

### pgAdmin → Railway Postgres
В сервисе Postgres → **Variables** включить публичный доступ (Public Networking),
взять `PGHOST/PGPORT/PGDATABASE/PGUSER/PGPASSWORD` и завести их как новое
подключение в pgAdmin (SSL mode: require).
