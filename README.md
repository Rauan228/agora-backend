# Agora Backend

Laravel API для B2B-маркетплейса упаковки **Agora**.

- **Репозиторий:** https://github.com/Rauan228/agora-backend  
- **Admin SPA (отдельный репо):** https://github.com/Rauan228/agota-admin-panel  
- **Product frontend:** https://agora-trade.vercel.app  

## Стек

| | |
|---|---|
| PHP | 8.3+ |
| Framework | Laravel 13 |
| DB | PostgreSQL (prod) / SQLite (local) |
| Auth (admin) | Laravel Sanctum Bearer tokens |
| API | REST JSON |

## API

| Зона | Префикс |
|---|---|
| Public (витрина) | `/api/suppliers`, `/api/offers` |
| Admin (React SPA) | `/api/admin/*` (Bearer) |
| Файлы (лого/фото) | `/files/{path}` |

Документация: [API.md](API.md)

## Модель каталога

- `suppliers` — компании-поставщики  
- `categories` — пилотные категории упаковки  
- `offers` — SKU: общие поля + `specs` (JSON по категории)  
- Схема полей: `config/agora.php`  
- Исходные доки фаундера: `docs/`

## Локальный запуск

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Админ: `admin@agora.local` / `password` (смени на проде).

### Env (важное)

```env
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=https://agora-trade.vercel.app
ADMIN_FRONTEND_URL=http://localhost:5173
```

На проде `ADMIN_FRONTEND_URL` = URL Vercel-админки.

## Admin API (кратко)

```http
POST /api/admin/login
{ "email": "...", "password": "..." }
→ { "token": "1|...", "user": {...} }

Authorization: Bearer 1|...
GET  /api/admin/me
GET  /api/admin/meta/categories
GET  /api/admin/meta/dictionaries
CRUD /api/admin/suppliers
CRUD /api/admin/offers
```

## Деплой

VPS или Railway (есть `railway.json`, `nixpacks.toml`).  
Нужны: Postgres, volume для `storage/app/public`, `APP_URL`, CORS origins.

```bash
php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force
php artisan db:seed --class=CategorySeeder --force
```
