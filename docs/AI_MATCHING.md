# Agora AI Matching — API для витрины

Каталог-заземлённый подбор: покупатель описывает задачу → structured query → детерминированный скоринг `offers` → shortlist + объяснение «почему» и «чего не хватает».

**Не выдумывает** поставщиков вне БД. LLM (WaveSpeed) опционален: без ключа работает heuristic-парсер, матчинг при этом полноценный.

---

## Base

```text
GET  /api/ai/catalog                    — размер области поиска (для честного UI)
POST /api/ai/sessions
POST /api/ai/sessions/{id}/messages     — блокирующий ответ
POST /api/ai/sessions/{id}/stream       — SSE: результаты сразу, текст потоком
GET  /api/ai/sessions/{id}
POST /api/ai/sessions/{id}/handoff
```

Prefix: `{APP_URL}/api`
Public, без auth. Rate limit ~30–40 req/min/IP.

---

## Ключевые принципы ответа

| Принцип | Как реализовано |
|---------|-----------------|
| Честный процент | `match_score` — доля **заявленных** требований, которые оффер закрыл. Критерии, о которых покупатель не просил, в знаменатель не входят |
| Нет ложных «точных совпадений» | Противоречие требованию (размер мимо, MOQ выше объёма, другая категория) снижает score и запрещает `match_tier: exact` |
| Незаполненные карточки не выигрывают | Отсутствие данных в `specs` не даёт баллов и попадает в `match_gaps` |
| Видна область поиска | `catalog_stats` / `GET /api/ai/catalog` — сколько офферов реально участвовало |
| Объяснимость в обе стороны | `match_reasons` (что подошло) и `match_gaps` (что расходится) |

### `match_tier`

| Значение | Смысл |
|----------|-------|
| `exact` | ≥75% и ни одного противоречия |
| `close` | ≥50% |
| `weak` | прошёл порог, но слабо |
| `fallback` | точного совпадения нет — ближайшее из каталога |

---

## Flow

```http
POST /api/ai/sessions
{}
```

```json
{
  "session_id": "uuid",
  "status": "active",
  "welcome": "... Сейчас в каталоге 11 активных офферов от 2 поставщиков.",
  "catalog": {
    "active_offers": 11,
    "active_suppliers": 2,
    "categories": { "Гофрокороба": 7 },
    "is_thin": true,
    "llm_enabled": true
  },
  "suggested_replies": ["...", "..."]
}
```

`is_thin` (каталог < 30 офферов) — сигнал показать баннер «выбор ограничен», чтобы слабая подборка не читалась как «AI тупой».

```http
POST /api/ai/sessions/{session_id}/messages
{ "message": "Самосбор 400×300×200, бурый, 5000 шт, Москва" }
```

```json
{
  "session_id": "uuid",
  "assistant_message": "Нашёл **1** точное совпадение...",
  "structured_query": { "category_slugs": ["corrugated-boxes"], "length_mm": 400, "qty": 5000 },
  "understood": [
    { "key": "category",   "label": "Категория", "value": "Гофрокороба" },
    { "key": "dimensions", "label": "Размер",    "value": "400×300×200 мм (±10%)" },
    { "key": "qty",        "label": "Объём",     "value": "5 000 шт" }
  ],
  "intent_source": "llm+heuristic",
  "catalog_stats": {
    "active_offers_total": 11,
    "offers_in_requested_category": 7,
    "scored_candidates": 3,
    "relaxed": null,
    "exact_count": 1,
    "top_score": 91
  },
  "offers": [
    {
      "id": 1,
      "offer_title": "Гофрокороб 400×300×200 Т-23",
      "match_score": 91,
      "match_tier": "exact",
      "match_reasons": ["Категория: Гофрокороба", "Размеры: Д 400 × Ш 300 × В 200 мм"],
      "match_gaps": ["цвет не указан"],
      "supplier": { "id": 1, "commercial_name": "...", "logo_url": null },
      "price_value": 28.5
    }
  ],
  "suppliers": [],
  "comparison": { "dimensions": [], "rows": [] },
  "suggested_replies": ["Нужна печать логотипа", "Сравни топ-3"],
  "cta": { "type": "request_quote", "label": "Передать менеджеру", "prefill": { "brief": "..." } }
}
```

`understood` — то, что AI извлёк, в человеческом виде. Показывать над результатами: это основной якорь доверия и точка, где пользователь ловит ошибку разбора («не так? напишите поправку»).

`catalog_stats.relaxed`:

| Значение | Что произошло | Что сказать в UI |
|----------|---------------|------------------|
| `null` | обычный поиск | — |
| `"category"` | в запрошенной категории пусто, искали по всему каталогу | «расширил поиск» |
| `"all_criteria"` | точного совпадения нет вообще | «ближайшее, с отметками расхождений» |

### Streaming (SSE)

```http
POST /api/ai/sessions/{id}/stream
Accept: text/event-stream
{ "message": "..." }
```

Порядок кадров:

| event | payload | назначение |
|-------|---------|-----------|
| `stage` | `{stage: "intent"\|"match"\|"compose"}` | прогресс по шагам |
| `understood` | `{understood, structured_query, intent_source}` | «я понял так» — до поиска |
| `results` | offers / suppliers / comparison | карточки появляются **до** текста |
| `delta` | `{text, replace?}` | токены ответа; `replace: true` — цельный фолбэк-текст |
| `done` | полный payload как у `/messages` | финал, сессия сохранена |
| `error` | `{message}` | ошибка внутри потока |

Отдаёт `X-Accel-Buffering: no` и `Cache-Control: no-transform`.

> **nginx на проде:** для локации `/api/` нужен `proxy_buffering off;`, иначе поток склеится в один пакет и стриминг не будет виден. Заголовок `X-Accel-Buffering` покрывает стандартный случай, но при нестандартном конфиге проверьте вручную.

Фронт должен уметь фолбэк: если поток не открылся или упал до первого `delta` — повторить запрос на `/messages`. Так сделано в админке.

```http
POST /api/ai/sessions/{id}/handoff
{ "contact": "+7...", "note": "Срочно" }
```

Бриф собирается из `understood` + shortlist, включая расхождения — менеджер видит и что нужно, и чего в каталоге не хватило.

---

## Env

```env
WAVESPEED_ENABLED=true
WAVESPEED_API_KEY=wsk_live_...
WAVESPEED_BASE_URL=https://llm.wavespeed.ai/v1
WAVESPEED_MODEL=deepseek/deepseek-v4-flash
```

Без `WAVESPEED_API_KEY` — heuristic-парсер и шаблонные формулировки; `catalog.llm_enabled: false` позволяет UI это показать.

---

## Полнота specs = качество матчинга

Скоринг читает и канонические, и краткие имена полей:

| Критерий | Ключи в `specs` |
|----------|-----------------|
| Размеры | `box_inner_*_mm`, `inner_*_mm`, `sheet_*_mm`, `box_outer_*_mm`, `*_mm` |
| Тип | `box_type`, `type` |
| Марка | `box_board_grade`, `board_grade`, `grade` |
| Профиль | `box_flute_profile`, `flute_profile`, `profile` |
| Цвет | `box_liner_color`, `liner_color`, `color` |
| Печать | `box_print_available`, `print_available` |

Если у оффера заполнен только `offer_title`, он честно получит низкий score и `match_gaps: ["размеры не заполнены в карточке"]`. Это не баг матчинга, а сигнал дозаполнить карточку в админке.

---

## Seed demo data

```bash
php artisan db:seed --class=OfferSeeder
# or full:
php artisan migrate --seed
```

---

## Architecture

| Class | Role |
|-------|------|
| `IntentParser` | message → StructuredQuery (LLM + heuristic) |
| `OfferMatcher` | скоринг активных офферов, нормализация 0–100, tiers, gaps |
| `AnswerComposer` | текст + chips; `streamMessages()` для SSE |
| `AiMatchingService` | `prepare()` → `finalizePreview()` → `finalize()`; `understood()`, бриф |
| `WaveSpeedClient` | OpenAI-compatible HTTP, `chat()` + `streamChat()` |

Tables: `ai_sessions`, `ai_messages`.

Разделение `prepare`/`finalize` — чтобы блокирующий и streaming-эндпоинты давали идентичные матчи.

---

## Tests

```bash
php artisan test --filter=AiMatchingTest
```

Покрыто: создание сессии и матч, handoff, история, `/catalog`, keywords не обнуляют выдачу, несовпавший размер никогда не `exact`, `understood`, SSE-кадры, пересортировка сохраняет реальные score.
