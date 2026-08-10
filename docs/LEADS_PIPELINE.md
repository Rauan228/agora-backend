# Лиды / обзвон — прототип

## Идея

```text
источник (CSV / Контур / ручной ввод / ссылка)
  → карточка лида
  → очередь обзвона (статусы)
  → interested / sent_kp → завести Supplier + Offer в админке
```

**Не входит в прототип:** массовый парсинг 2ГИС, Яндекс.Карт, Авито, Google.  
Прокси Decodo для этого **не подключаем** — только легальный/ручной сбор + импорт.

## API (admin, Bearer)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/api/admin/leads` | список (`q`, `call_status`, `source`, `region`, `category_slug`) |
| GET | `/api/admin/leads/stats` | счётчики по статусам |
| GET | `/api/admin/leads/import-template` | CSV-шаблон |
| POST | `/api/admin/leads/import` | `multipart file` + defaults |
| POST | `/api/admin/leads` | создать |
| PATCH | `/api/admin/leads/{id}` | обновить (статус звонка) |
| DELETE | `/api/admin/leads/{id}` | удалить |

## Статусы `call_status`

`new` → `to_call` → `no_answer` / `callback` → `interested` → `sent_kp` → `onboarded`  
или `rejected` / `wrong_number` / `duplicate`

## Источники `source`

`manual` | `csv` | `kontur` | `website` | `maps_manual` | `ads_manual` | `other`

Карты и объявления — **только ручной** занос (`maps_manual`, `ads_manual`) + `source_url`.

## CSV из Контура

1. Выгрузка в Excel/CSV из Компас.  
2. Переименовать колонки под шаблон (или добавить aliases в импорте).  
3. Импорт в админке «Лиды» → Import.  
4. `default_category_slug=corrugated-boxes`, `default_region=Москва`.

Минимальные колонки: `company_name`, желательно `phone` или `inn`.

## Что нужно от тебя для развития

1. **Выгрузка-пример** из Контур.Компас (1 CSV, 20–50 строк, без секретов паролей).  
2. Список **поисковых запросов** Стаса (гофра / МО / опт).  
3. Нужен ли деплой лидов на **тот же VPS** (да/нет).  
4. Прокси Decodo — **не для парсинга карт**; если позже легальный API — обсудим отдельно.  
5. Скрипт обзвона (1 абзац) — положим в `notes` шаблона.

## Дальше (не сейчас)

- кнопка «создать поставщика из лида»  
- очередь «звонки на сегодня» по `next_call_at`  
- интеграция с телефонией  
