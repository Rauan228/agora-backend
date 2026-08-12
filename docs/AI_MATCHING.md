# Agora AI Matching — API для витрины

Каталог-заземлённый чат: покупатель описывает задачу → structured query → скоринг `offers` → shortlist + объяснения.

**Не выдумывает** поставщиков вне БД. LLM (WaveSpeed) опционален: без ключа работает heuristic-парсер.

---

## Base

```text
POST /api/ai/sessions
POST /api/ai/sessions/{id}/messages
GET  /api/ai/sessions/{id}
POST /api/ai/sessions/{id}/handoff
```

Prefix: `{APP_URL}/api`  
Public, без auth. Rate limit ~30–40 req/min/IP.

---

## Flow

```http
POST /api/ai/sessions
Content-Type: application/json

{}
```

```json
{
  "session_id": "uuid",
  "status": "active",
  "welcome": "...",
  "suggested_replies": ["...", "..."]
}
```

```http
POST /api/ai/sessions/{session_id}/messages
Content-Type: application/json

{ "message": "Самосбор 400×300×200, бурый, 5000 шт, Москва" }
```

Ответ (сокращённо):

```json
{
  "session_id": "uuid",
  "assistant_message": "Нашёл **N** подходящих...",
  "structured_query": {
    "category_slugs": ["corrugated-boxes"],
    "length_mm": 400,
    "width_mm": 300,
    "height_mm": 200,
    "liner_color": "Бурый",
    "qty": 5000,
    "city": "Москва",
    "delivery_moscow": true,
    "confidence": 0.75,
    "missing_slots": [],
    "clarifying_question": null
  },
  "intent_source": "heuristic",
  "offers": [
    {
      "id": 1,
      "offer_title": "...",
      "match_score": 72,
      "match_reasons": ["Категория: Гофрокороба", "Размеры: ..."],
      "supplier": { "id": 1, "commercial_name": "...", "logo_url": null },
      "price_value": 28.5,
      "specs": {}
    }
  ],
  "suppliers": [],
  "comparison": { "dimensions": [], "rows": [] },
  "suggested_replies": ["Нужна печать логотипа", "Сравни топ-3"],
  "cta": {
    "type": "request_quote",
    "label": "Передать менеджеру",
    "prefill": { "session_id": "...", "brief": "..." }
  }
}
```

```http
POST /api/ai/sessions/{id}/handoff
{ "contact": "+7...", "note": "Срочно" }
```

---

## Env

```env
WAVESPEED_ENABLED=true
WAVESPEED_API_KEY=wsk_live_...
WAVESPEED_BASE_URL=https://llm.wavespeed.ai/v1
WAVESPEED_MODEL=deepseek/deepseek-v4-flash
```

Без `WAVESPEED_API_KEY` — только heuristic (достаточно для MVP и тестов).

---

## UI (Next.js)

Рекомендуемый split:

- слева: чат (`welcome` + messages + chips `suggested_replies`)
- справа: карточки `offers` + таблица `comparison.rows`
- кнопка CTA → `handoff` или ваша форма заявки с `prefill.brief`

Не рендерить «свободный» список URL от LLM — только `offers[].id` из ответа API.

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
| `OfferMatcher` | Eloquent + score active offers |
| `AnswerComposer` | text + chips |
| `AiMatchingService` | session orchestration |
| `WaveSpeedClient` | OpenAI-compatible HTTP |

Tables: `ai_sessions`, `ai_messages`.
