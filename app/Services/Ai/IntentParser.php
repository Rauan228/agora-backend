<?php

namespace App\Services\Ai;

/**
 * Message → StructuredQuery for catalog matching.
 * Uses WaveSpeed when available; always has heuristic fallback.
 */
class IntentParser
{
    public function __construct(
        private WaveSpeedClient $llm,
    ) {}

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  list<array{role: string, content: string}>  $history
     * @return array{query: array<string, mixed>, source: string, model?: string, usage?: array}
     */
    public function parse(string $userMessage, ?array $previous = null, array $history = []): array
    {
        $heuristic = $this->heuristic($userMessage, $previous);

        if ($this->llm->enabled()) {
            $llmResult = $this->viaLlm($userMessage, $previous, $history);
            if ($llmResult !== null) {
                $merged = $this->mergeQueries($previous ?? [], $llmResult['query']);
                // fill gaps from heuristic (numbers often better from regex)
                $merged = $this->mergeQueries($heuristic, $merged);
                $merged = $this->normalize($merged);

                $out = [
                    'query' => $merged,
                    'source' => 'llm+heuristic',
                    'model' => $llmResult['model'] ?? null,
                ];
                if (! empty($llmResult['usage'])) {
                    $out['usage'] = $llmResult['usage'];
                }

                return $out;
            }
        }

        return [
            'query' => $this->normalize($this->mergeQueries($previous ?? [], $heuristic)),
            'source' => 'heuristic',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  list<array{role: string, content: string}>  $history
     * @return array{query: array<string, mixed>, model: string, usage?: array}|null
     */
    private function viaLlm(string $userMessage, ?array $previous, array $history): ?array
    {
        $categories = collect(config('agora.categories', []))
            ->map(fn ($c) => ($c['slug'] ?? '').' — '.($c['name'] ?? ''))
            ->implode("\n");

        $prevJson = json_encode($previous ?? new \stdClass, JSON_UNESCAPED_UNICODE);

        $system = <<<PROMPT
Ты парсер запросов B2B-маркетплейса упаковки Agora (Москва/МО).
Из сообщения покупателя собери JSON StructuredQuery. Не выдумывай числа, которых нет.
Допустимые category_slugs (можно несколько или пусто):
{$categories}

Схема JSON (все поля опциональны кроме confidence):
{
  "category_slugs": ["corrugated-boxes"],
  "city": "Москва",
  "delivery_moscow": true,
  "box_type": "Самосборный|Четырёхклапанный|...",
  "length_mm": 400,
  "width_mm": 300,
  "height_mm": 200,
  "size_tolerance_pct": 10,
  "board_grade": "Т-23",
  "flute_profile": "B",
  "liner_color": "Бурый",
  "print_needed": true,
  "branding_needed": false,
  "qty": 5000,
  "moq_max": 1000,
  "lead_time_days_max": 14,
  "keywords": ["e-com"],
  "missing_slots": ["height_mm"],
  "confidence": 0.0,
  "clarifying_question": "строка или null"
}

Правила:
- Гофрокороб/коробка/FEFCO → corrugated-boxes
- Гофролист/листовой гофрокартон → corrugated-sheet
- Стрейч → stretch-film; пузырчатая → bubble-wrap; скотч → packing-tape
- Размеры вида 400x300x200 (мм) → length/width/height
- Если объём «5000 в месяц» → qty
- missing_slots: что критично спросить (макс 2)
- clarifying_question: один короткий вопрос на русском, если confidence < 0.55 или есть missing_slots
- Учитывай previous_query и дополняй, не затирай известное null-ами
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];
        foreach (array_slice($history, -6) as $h) {
            if (in_array($h['role'] ?? '', ['user', 'assistant'], true)) {
                $messages[] = [
                    'role' => $h['role'],
                    'content' => mb_substr((string) $h['content'], 0, 1500),
                ];
            }
        }
        $messages[] = [
            'role' => 'user',
            'content' => "previous_query: {$prevJson}\n\nuser_message: {$userMessage}",
        ];

        $resp = $this->llm->chat($messages, json: true, temperature: 0.1);
        if ($resp === null) {
            return null;
        }

        $parsed = json_decode($resp['content'], true);
        if (! is_array($parsed)) {
            return null;
        }

        $out = [
            'query' => $parsed,
            'model' => $resp['model'],
        ];
        if (! empty($resp['usage'])) {
            $cost = LlmCost::fromUsage($resp['usage'], $resp['model'] ?? null);
        } else {
            $promptText = collect($messages)->pluck('content')->implode("\n");
            $cost = LlmCost::estimateFromText($promptText, $resp['content'], $resp['model'] ?? null);
        }
        $cost['label'] = 'intent_parse';
        $out['usage'] = $cost;

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    public function heuristic(string $message, ?array $previous = null): array
    {
        $t = mb_strtolower($message);
        $q = $previous ? $this->normalize($previous) : $this->emptyQuery();

        // categories
        $slugs = $q['category_slugs'] ?? [];
        $map = [
            'corrugated-boxes' => ['гофрокороб', 'гофрокороба', 'коробк', 'гофроящик', 'fefco', 'самосбор', 'четырёхклапан', 'четырехклапан', 'гофротара'],
            'corrugated-sheet' => ['гофролист', 'листовой гофро', 'гофрокартон лист', 'лист гофро'],
            'stretch-film' => ['стрейч', 'стретч', 'stretch'],
            'shrink-film' => ['термоусад', 'shrink'],
            'bubble-wrap' => ['пузырчат', 'воздушно-пузыр', 'ВПП'],
            'packing-tape' => ['скотч', 'клейкая лента'],
            'strapping-tape' => ['стреппинг', 'стреппинг-лент', 'пп лента'],
            'courier-bags' => ['курьерск', 'сейф-пакет', 'пакет курьер'],
            'zip-lock' => ['zip', 'зип', 'zip-lock', 'зип-лок'],
            'fillers' => ['наполнител', 'воздушн подуш'],
            'thermal-labels' => ['термоэтикет', 'этикетк'],
            'pallets' => ['паллет', 'поддон'],
            'foam-pe' => ['вспенен', 'пенополиэтилен', 'ппэ'],
        ];
        foreach ($map as $slug => $words) {
            foreach ($words as $w) {
                if (str_contains($t, mb_strtolower($w))) {
                    $slugs[] = $slug;
                    break;
                }
            }
        }
        if ($slugs === [] && preg_match('/упаков|тара|короб/u', $t)) {
            $slugs[] = 'corrugated-boxes';
        }
        $q['category_slugs'] = array_values(array_unique($slugs));

        // sizes 400x300x200 or 400×300×200
        if (preg_match('/(\d{2,4})\s*[xх×*]\s*(\d{2,4})(?:\s*[xх×*]\s*(\d{2,4}))?/u', $t, $m)) {
            $q['length_mm'] = (int) $m[1];
            $q['width_mm'] = (int) $m[2];
            if (! empty($m[3])) {
                $q['height_mm'] = (int) $m[3];
            }
        }

        // board grade
        if (preg_match('/\b([тt]-?\d{2}|[пp]-?\d{2})\b/ui', $message, $m)) {
            $g = mb_strtoupper(str_replace(['t', 'T', 'p', 'P'], ['Т', 'Т', 'П', 'П'], $m[1]));
            $g = preg_replace('/^(Т|П)(\d)/u', '$1-$2', $g) ?: $g;
            $q['board_grade'] = $g;
        }

        // flute
        if (preg_match('/профиль\s*([ebc]{1,2})\b/ui', $t, $m)) {
            $q['flute_profile'] = mb_strtoupper($m[1]);
        } elseif (preg_match('/\b(профиль\s+)?([ebc]|bc|be)\b/ui', $t, $m) && isset($m[2]) && mb_strlen($m[2]) <= 2) {
            // careful — skip bare letters unless with profile word handled
        }

        // color
        if (str_contains($t, 'бур')) {
            $q['liner_color'] = 'Бурый';
        } elseif (str_contains($t, 'бел')) {
            $q['liner_color'] = 'Белый';
        } elseif (str_contains($t, 'крафт')) {
            $q['liner_color'] = 'Крафт';
        }

        // box type
        if (str_contains($t, 'самосбор')) {
            $q['box_type'] = 'Самосборный';
        } elseif (str_contains($t, 'четырёхклапан') || str_contains($t, 'четырехклапан') || str_contains($t, '0201')) {
            $q['box_type'] = 'Четырёхклапанный';
        } elseif (str_contains($t, 'крышка') && str_contains($t, 'дно')) {
            $q['box_type'] = 'Крышка-дно';
        } elseif (str_contains($t, 'почтово') || str_contains($t, 'почтовый')) {
            $q['box_type'] = 'Почтовый';
        }

        // print / branding
        if (preg_match('/печат|логотип|брендир/u', $t)) {
            $q['print_needed'] = true;
            $q['branding_needed'] = true;
        }

        // qty
        if (preg_match('/(\d[\d\s]{0,8})\s*(шт|штук|коробок|короба)/u', $t, $m)) {
            $q['qty'] = (int) preg_replace('/\s+/', '', $m[1]);
        } elseif (preg_match('/(\d[\d\s]{2,8})\s*(в\s*месяц|\/мес|мес)/u', $t, $m)) {
            $q['qty'] = (int) preg_replace('/\s+/', '', $m[1]);
        }

        // geo
        if (preg_match('/москв|мск|\bмо\b|подмосков/u', $t)) {
            $q['city'] = 'Москва';
            $q['delivery_moscow'] = true;
        }

        // lead time
        if (preg_match('/за\s*(\d{1,2})\s*дн/u', $t, $m)) {
            $q['lead_time_days_max'] = (int) $m[1];
        } elseif (str_contains($t, 'срочно')) {
            $q['lead_time_days_max'] = 7;
        }

        // keywords
        $keywords = $q['keywords'] ?? [];
        foreach (['e-com', 'ecom', 'маркетплейс', 'wb', 'ozon', 'food', 'пищев', 'косметик'] as $kw) {
            if (str_contains($t, $kw)) {
                $keywords[] = $kw;
            }
        }
        $q['keywords'] = array_values(array_unique($keywords));

        // missing slots for boxes
        $missing = [];
        if (in_array('corrugated-boxes', $q['category_slugs'] ?? [], true)
            || ($q['category_slugs'] ?? []) === []) {
            if (empty($q['length_mm']) || empty($q['width_mm'])) {
                $missing[] = 'length_mm';
            }
            if (empty($q['height_mm'])) {
                $missing[] = 'height_mm';
            }
        }
        if (empty($q['qty'])) {
            $missing[] = 'qty';
        }
        $q['missing_slots'] = array_slice(array_values(array_unique($missing)), 0, 2);

        $filled = 0;
        foreach (['category_slugs', 'length_mm', 'width_mm', 'height_mm', 'box_type', 'qty', 'city'] as $k) {
            if (! empty($q[$k])) {
                $filled++;
            }
        }
        $q['confidence'] = min(0.95, 0.25 + $filled * 0.1);
        $q['clarifying_question'] = null;
        if ($q['missing_slots'] !== [] && ($q['confidence'] ?? 0) < 0.7) {
            $q['clarifying_question'] = $this->defaultQuestion($q['missing_slots'][0]);
        }

        return $q;
    }

    private function defaultQuestion(string $slot): string
    {
        return match ($slot) {
            'length_mm', 'width_mm', 'height_mm' => 'Какой внутренний размер короба нужен (Д×Ш×В в мм)?',
            'qty' => 'Какой объём заказа (шт) или сколько в месяц?',
            'box_type' => 'Какой тип: самосборный, четырёхклапанный или другой?',
            'city' => 'Доставка нужна в Москву/МО или другой регион?',
            default => 'Уточните, пожалуйста, ключевые параметры заказа.',
        };
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function mergeQueries(array $base, array $over): array
    {
        $out = $base;
        foreach ($over as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            if ($k === 'category_slugs' && is_array($v)) {
                $out[$k] = array_values(array_unique(array_merge($out[$k] ?? [], $v)));
                continue;
            }
            if ($k === 'keywords' && is_array($v)) {
                $out[$k] = array_values(array_unique(array_merge($out[$k] ?? [], $v)));
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public function normalize(array $q): array
    {
        $out = $this->emptyQuery();
        foreach ($out as $k => $default) {
            if (array_key_exists($k, $q) && $q[$k] !== null && $q[$k] !== '') {
                $out[$k] = $q[$k];
            }
        }

        // coerce types
        foreach (['length_mm', 'width_mm', 'height_mm', 'qty', 'moq_max', 'lead_time_days_max', 'size_tolerance_pct'] as $n) {
            if (isset($out[$n]) && $out[$n] !== null) {
                $out[$n] = (int) $out[$n];
            }
        }
        foreach (['print_needed', 'branding_needed', 'delivery_moscow'] as $b) {
            if (isset($out[$b])) {
                $out[$b] = (bool) $out[$b];
            }
        }
        if (isset($out['confidence'])) {
            $out['confidence'] = (float) $out['confidence'];
        }
        if (! is_array($out['category_slugs'] ?? null)) {
            $out['category_slugs'] = [];
        }
        if (! is_array($out['keywords'] ?? null)) {
            $out['keywords'] = [];
        }
        if (! is_array($out['missing_slots'] ?? null)) {
            $out['missing_slots'] = [];
        }
        // valid category slugs only
        $valid = collect(config('agora.categories', []))->pluck('slug')->all();
        $out['category_slugs'] = array_values(array_intersect($out['category_slugs'], $valid));

        if (empty($out['size_tolerance_pct'])) {
            $out['size_tolerance_pct'] = 10;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyQuery(): array
    {
        return [
            'category_slugs' => [],
            'city' => null,
            'delivery_moscow' => null,
            'box_type' => null,
            'length_mm' => null,
            'width_mm' => null,
            'height_mm' => null,
            'size_tolerance_pct' => 10,
            'board_grade' => null,
            'flute_profile' => null,
            'liner_color' => null,
            'print_needed' => null,
            'branding_needed' => null,
            'qty' => null,
            'moq_max' => null,
            'lead_time_days_max' => null,
            'keywords' => [],
            'missing_slots' => [],
            'confidence' => 0.0,
            'clarifying_question' => null,
        ];
    }
}
