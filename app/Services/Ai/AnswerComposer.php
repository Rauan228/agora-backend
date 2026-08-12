<?php

namespace App\Services\Ai;

use App\Models\Offer;

/**
 * Builds assistant text + suggested replies from query + matches.
 * Optional LLM polish; safe template fallback.
 */
class AnswerComposer
{
    public function __construct(
        private WaveSpeedClient $llm,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     * @return array{message: string, suggested_replies: list<string>, llm_used: bool, usage?: array}
     */
    public function compose(
        array $query,
        array $matches,
        string $userMessage,
        array $stats = [],
        array $turn = [],
    ): array {
        $template = $this->template($query, $matches, $stats, $turn);
        $suggested = $this->suggestedReplies($query, $matches, $turn);

        // Conversational turns (greeting, meta, reset) still get an LLM voice —
        // that's exactly where a canned template feels robotic.
        $conversational = ($turn['should_match'] ?? true) === false;

        if ($this->llm->enabled() && ($matches !== [] || $conversational)) {
            $polish = $this->polishWithLlm($template, $query, $matches, $userMessage, $stats, $turn);
            if ($polish && ! empty($polish['message'])) {
                return [
                    'message' => $polish['message'],
                    'suggested_replies' => $suggested,
                    'llm_used' => true,
                    'usage' => $polish['usage'] ?? null,
                ];
            }
        }

        return [
            'message' => $template,
            'suggested_replies' => $suggested,
            'llm_used' => false,
        ];
    }

    /**
     * Messages for a streaming LLM call. Returns null when streaming should be
     * skipped (no LLM, or nothing to talk about) — caller falls back to template.
     *
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     * @return list<array{role: string, content: string}>|null
     */
    public function streamMessages(
        array $query,
        array $matches,
        string $userMessage,
        array $stats = [],
        array $turn = [],
    ): ?array {
        $conversational = ($turn['should_match'] ?? true) === false;

        if (! $this->llm->enabled() || ($matches === [] && ! $conversational)) {
            return null;
        }

        return $this->llmMessages(
            $this->template($query, $matches, $stats, $turn),
            $query,
            $matches,
            $userMessage,
            $stats,
            $turn,
            json: false,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     */
    public function template(array $query, array $matches, array $stats = [], array $turn = []): string
    {
        $parts = [];
        $total = (int) ($stats['active_offers_total'] ?? 0);

        // ---- conversational turns: no search happened ------------------------
        if (($turn['should_match'] ?? true) === false) {
            return $this->conversationalTemplate($query, $stats, $turn);
        }

        // ---- acknowledge what changed, before the results --------------------
        $ack = $this->acknowledgement($query, $turn);
        if ($ack !== null) {
            $parts[] = $ack;
        }

        if ($matches === []) {
            $parts[] = $total === 0
                ? 'В каталоге пока нет активных офферов, поэтому подбирать не из чего. Как только поставщики будут заведены, подбор заработает.'
                : 'В каталоге ('.$this->plural($total, 'активный оффер', 'активных оффера', 'активных офферов')
                    .') нет ничего близкого под этот запрос.';
            $parts[] = 'Можно уточнить категорию, размеры в мм или объём — либо оставить заявку менеджеру, он поищет поставщика вне текущего каталога.';

            return implode("\n\n", $parts);
        }

        $exact = (int) ($stats['exact_count'] ?? 0);
        $relaxed = $stats['relaxed'] ?? null;
        $n = count($matches);

        // Be honest about *what kind* of result this is before listing it.
        if ($relaxed === 'all_criteria') {
            $parts[] = 'Точного совпадения в каталоге нет. Показываю **'.$n.'** ближайших варианта — по ним видно, чего не хватает.';
        } elseif ($relaxed === 'category') {
            $parts[] = 'В запрошенной категории подходящего не нашлось, поэтому расширил поиск по всему каталогу: **'.$n.'** вариант(ов).';
        } elseif ($exact > 0) {
            $parts[] = 'Нашёл **'.$exact.'** точн'.($exact === 1 ? 'ое совпадение' : 'ых совпадения').' и ещё '.($n - $exact).' близких:';
        } else {
            $parts[] = 'Точных совпадений нет, но есть **'.$n.'** близких варианта:';
        }

        foreach (array_slice($matches, 0, 5) as $i => $row) {
            /** @var Offer $o */
            $o = $row['offer'];
            $num = $i + 1;
            $price = $o->price_hidden || $o->price_value === null
                ? 'цена по запросу'
                : ((float) $o->price_value).' '.$o->currency.'/'.$o->price_basis;
            $supplier = $o->supplier?->commercial_name ?? 'Поставщик';
            $line = "{$num}. **{$o->offer_title}** — {$supplier}. {$price}. Соответствие {$row['score']}%.";
            if (! empty($row['reasons'])) {
                $line .= ' Подходит: '.implode('; ', array_slice($row['reasons'], 0, 3)).'.';
            }
            if (! empty($row['gaps'])) {
                $line .= ' Расхождения: '.implode('; ', array_slice($row['gaps'], 0, 2)).'.';
            }
            $parts[] = $line;
        }

        if ($total > 0 && $total < 30) {
            $parts[] = '_Каталог пока небольшой ('.$this->plural($total, 'активный оффер', 'активных оффера', 'активных офферов')
                .'), поэтому выбор ограничен. Менеджер может добрать поставщиков под задачу._';
        }

        // Ask for at most one thing, and only when it is genuinely unknown.
        if (! empty($query['missing_slots'])) {
            $parts[] = 'Чтобы сузить выбор: '.$this->slotHint((string) $query['missing_slots'][0]);
        } elseif (count($matches) > 1) {
            $parts[] = 'Могу сравнить варианты, отсортировать по цене или передать запрос менеджеру с готовым брифом.';
        } else {
            $parts[] = 'Если вариант подходит — передам запрос менеджеру с готовым брифом.';
        }

        return implode("\n\n", $parts);
    }

    /** Human labels for query fields, used in acknowledgements. */
    private const FIELD_LABELS = [
        'category_slugs' => 'категорию',
        'city' => 'город',
        'delivery_moscow' => 'доставку',
        'box_type' => 'тип короба',
        'length_mm' => 'длину',
        'width_mm' => 'ширину',
        'height_mm' => 'высоту',
        'board_grade' => 'марку картона',
        'flute_profile' => 'профиль гофры',
        'liner_color' => 'цвет',
        'print_needed' => 'печать',
        'branding_needed' => 'брендирование',
        'qty' => 'объём',
        'moq_max' => 'ограничение по MOQ',
        'lead_time_days_max' => 'срок',
    ];

    /**
     * Reply for a turn where nothing was searched: greeting, meta question, or
     * an explicit reset. This is where the assistant stops sounding like a
     * search box.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $stats
     * @param  array<string, mixed>  $turn
     */
    private function conversationalTemplate(array $query, array $stats, array $turn): string
    {
        $kind = $turn['turn_kind'] ?? TurnInterpreter::KIND_SMALL_TALK;
        $total = (int) ($stats['active_offers_total'] ?? 0);

        if ($kind === TurnInterpreter::KIND_RESET) {
            return "Хорошо, начинаем с чистого листа — прежние параметры сбросил.\n\n"
                .'Опишите новую задачу: что упаковываем, размеры в мм, объём и город.';
        }

        if ($kind === TurnInterpreter::KIND_META) {
            $lines = [
                'Я подбираю упаковку из каталога Agora: понимаю задачу словами, ищу по офферам поставщиков и объясняю, почему подходит именно это.',
                'Сейчас в каталоге '.$this->plural($total, 'активный оффер', 'активных оффера', 'активных офферов')
                    .'. Искать по всему рынку я не умею — только по заведённым товарам.',
                'Скажите задачу как коллеге: например «короба 400×300×200 под маркетплейсы, 5000 шт в месяц, Москва». Уточнения можно дописывать по ходу — я помню контекст.',
            ];

            return implode("\n\n", $lines);
        }

        // Greeting — pick up where we left off if there is a running query.
        $running = $this->runningSummary($query);
        if ($running !== null) {
            return "Здравствуйте! Мы остановились на: {$running}.\n\n"
                .'Продолжаем с этими параметрами или уточним что-то?';
        }

        return "Здравствуйте! Помогу подобрать упаковку под задачу.\n\n"
            .'Расскажите, что нужно: тип упаковки, размеры в мм, примерный объём и город доставки. '
            .'Достаточно одной фразы — детали можно дописать потом.';
    }

    /**
     * «Понял, добавил бурый цвет» / «Переключился на гофролист».
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $turn
     */
    private function acknowledgement(array $query, array $turn): ?string
    {
        $kind = $turn['turn_kind'] ?? null;
        $added = $turn['added_fields'] ?? [];
        $dropped = $turn['dropped_fields'] ?? [];
        $switched = $turn['switched_from'] ?? [];

        if ($kind === TurnInterpreter::KIND_TOPIC_SWITCH) {
            $catName = $this->categoryNames($query);
            $msg = $catName !== null
                ? "Переключился на **{$catName}**."
                : 'Понял, новая категория.';
            // Mention new constraints from this same message (e.g. «Т-23»).
            $fresh = array_values(array_diff($added, ['category_slugs']));
            if ($fresh !== []) {
                $msg .= ' Учёл '.$this->labelList($fresh).'.';
            }
            if ($switched !== []) {
                $msg .= ' Параметры от прошлого запроса ('.$this->labelList($switched).') сбросил — они не относятся к этой категории.';
            }
            $kept = $this->keptSummary($query);
            if ($kept !== null) {
                $msg .= " Оставил {$kept}.";
            }

            return $msg;
        }

        if ($dropped !== []) {
            $msg = 'Убрал из запроса '.$this->labelList($dropped).'.';
            $running = $this->runningSummary($query);
            if ($running !== null) {
                $msg .= " Сейчас ищу: {$running}.";
            }

            return $msg;
        }

        if ($kind === TurnInterpreter::KIND_REFINE && $added !== []) {
            // Dimensions arrive as three fields — say it once.
            $labels = $this->labelList($added);

            return 'Понял, учёл '.$labels.'.';
        }

        if ($kind === TurnInterpreter::KIND_SORT) {
            return null; // the sort line itself is enough
        }

        return null;
    }

    /**
     * Human label for a set of removed fields — used to log the UI edit in the
     * transcript ("Убрать из запроса: цвет").
     *
     * @param  list<string>  $fields
     */
    public function describeRemoval(array $fields): ?string
    {
        return $fields === [] ? null : $this->labelList($fields);
    }

    /**
     * @param  list<string>  $fields
     */
    private function labelList(array $fields): string
    {
        $dims = ['length_mm', 'width_mm', 'height_mm'];
        $hasDims = array_intersect($dims, $fields) !== [];

        $labels = [];
        if ($hasDims) {
            $labels[] = 'размеры';
        }
        foreach ($fields as $f) {
            if (in_array($f, $dims, true)) {
                continue;
            }
            $label = self::FIELD_LABELS[$f] ?? null;
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        $labels = array_values(array_unique($labels));

        return $labels === [] ? 'уточнение' : implode(', ', $labels);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function categoryNames(array $query): ?string
    {
        $slugs = $query['category_slugs'] ?? [];
        if (! is_array($slugs) || $slugs === []) {
            return null;
        }
        $names = collect(config('agora.categories', []))
            ->whereIn('slug', $slugs)
            ->pluck('name')
            ->all();

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * Compact one-line summary of the running query, e.g.
     * «гофрокороба 400×300×200 мм, бурый, 5000 шт, Москва».
     *
     * @param  array<string, mixed>  $query
     */
    private function runningSummary(array $query): ?string
    {
        $bits = [];

        $cat = $this->categoryNames($query);
        if ($cat !== null) {
            $bits[] = mb_strtolower($cat);
        }
        if (! empty($query['box_type'])) {
            $bits[] = mb_strtolower((string) $query['box_type']);
        }
        $dims = array_filter([
            $query['length_mm'] ?? null,
            $query['width_mm'] ?? null,
            $query['height_mm'] ?? null,
        ]);
        if ($dims !== []) {
            $bits[] = implode('×', $dims).' мм';
        }
        if (! empty($query['board_grade'])) {
            $bits[] = (string) $query['board_grade'];
        }
        if (! empty($query['liner_color'])) {
            $bits[] = mb_strtolower((string) $query['liner_color']);
        }
        if (($query['print_needed'] ?? null) === true) {
            $bits[] = 'с печатью';
        }
        if (! empty($query['qty'])) {
            $bits[] = number_format((int) $query['qty'], 0, '.', ' ').' шт';
        }
        if (! empty($query['city'])) {
            $bits[] = (string) $query['city'];
        }
        if (! empty($query['lead_time_days_max'])) {
            $bits[] = 'до '.(int) $query['lead_time_days_max'].' дн.';
        }

        return $bits === [] ? null : implode(', ', $bits);
    }

    /** Cross-category constraints that survive a topic switch. */
    private function keptSummary(array $query): ?string
    {
        $bits = [];
        if (! empty($query['city'])) {
            $bits[] = (string) $query['city'];
        }
        if (! empty($query['qty'])) {
            $bits[] = number_format((int) $query['qty'], 0, '.', ' ').' шт';
        }
        if (! empty($query['lead_time_days_max'])) {
            $bits[] = 'срок до '.(int) $query['lead_time_days_max'].' дн.';
        }

        return $bits === [] ? null : implode(', ', $bits);
    }

    /** Russian plural: 1 оффер / 2 оффера / 5 офферов. */
    private function plural(int $n, string $one, string $few, string $many): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $n.' '.$many;
        }
        if ($mod10 === 1) {
            return $n.' '.$one;
        }
        if ($mod10 >= 2 && $mod10 <= 4) {
            return $n.' '.$few;
        }

        return $n.' '.$many;
    }

    private function slotHint(string $slot): string
    {
        return match ($slot) {
            'length_mm', 'width_mm', 'height_mm' => 'укажите размер Д×Ш×В в мм.',
            'qty' => 'назовите объём (шт разово или в месяц).',
            'box_type' => 'уточните тип короба (самосбор / 4-клапан и т.д.).',
            'city' => 'уточните город доставки.',
            'category' => 'скажите, что именно упаковываем — короба, плёнка, лоток.',
            default => 'добавьте ещё деталей по задаче.',
        };
    }

    /**
     * Suggested replies for a prepared turn (used by the streaming endpoint,
     * which composes prose separately).
     *
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @return list<string>
     */
    public function repliesFor(array $query, array $matches, array $turn = []): array
    {
        return $this->suggestedReplies($query, $matches, $turn);
    }

    /**
     * Context-aware follow-ups: only offer what actually moves this conversation
     * forward, and never suggest re-stating something already known.
     *
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $turn
     * @return list<string>
     */
    private function suggestedReplies(array $query, array $matches, array $turn = []): array
    {
        $isBoxes = in_array('corrugated-boxes', $query['category_slugs'] ?? [], true);
        $conversational = ($turn['should_match'] ?? true) === false;

        // Nothing searched yet — offer entry points, not refinements.
        if ($conversational && ! $this->hasConstraints($query)) {
            return [
                'Короба под маркетплейсы, Москва',
                'Самосбор 400×300×200, 5000 шт',
                'Гофролист Т-23 оптом',
                'Что есть в каталоге?',
            ];
        }

        $out = [];

        // Missing essentials first — these change the result the most.
        if (empty($query['length_mm']) && $isBoxes) {
            $out[] = 'Размер 400×300×200 мм';
        }
        if (empty($query['qty'])) {
            $out[] = 'Нужно около 5000 шт/мес';
        }
        if (($query['delivery_moscow'] ?? null) !== true && empty($query['city'])) {
            $out[] = 'Доставка в Москву';
        }
        if (($query['print_needed'] ?? null) !== true) {
            $out[] = 'Нужна печать логотипа';
        }

        // Offer to relax a constraint when the result is thin — the buyer often
        // doesn't realise which requirement is the expensive one.
        $weak = $matches !== [] && ($matches[0]['score'] ?? 0) < OfferMatcher::TIER_EXACT;
        if (($weak || count($matches) <= 1)) {
            if (! empty($query['liner_color'])) {
                $out[] = 'Цвет не важен';
            } elseif (! empty($query['board_grade'])) {
                $out[] = 'Марка не важна';
            } elseif (! empty($query['box_type'])) {
                $out[] = 'Тип короба не важен';
            }
        }

        if (count($matches) >= 2) {
            $out[] = 'Сравни топ-3';
            $out[] = 'Покажи дешевле';
        }

        if ($matches !== []) {
            $out[] = 'Передать менеджеру';
        }

        return array_slice(array_values(array_unique($out)), 0, 4);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function hasConstraints(array $query): bool
    {
        foreach (['category_slugs', 'length_mm', 'qty', 'city', 'box_type', 'board_grade', 'liner_color'] as $k) {
            $v = $query[$k] ?? null;
            if ($v !== null && $v !== '' && $v !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     * @return list<array{role: string, content: string}>
     */
    private function llmMessages(
        string $template,
        array $query,
        array $matches,
        string $userMessage,
        array $stats,
        array $turn = [],
        bool $json = true,
    ): array {
        $cards = [];
        foreach (array_slice($matches, 0, 5) as $row) {
            $o = $row['offer'];
            $cards[] = [
                'id' => $o->id,
                'title' => $o->offer_title,
                'supplier' => $o->supplier?->commercial_name,
                'score' => $row['score'],
                'tier' => $row['tier'] ?? null,
                'matched' => $row['reasons'],
                'gaps' => $row['gaps'] ?? [],
            ];
        }

        $rules = <<<'RULES'
Ты Agora AI — консультант по закупке упаковки. Ты ведёшь ЖИВОЙ ДИАЛОГ, а не выдаёшь отчёты.

Тон:
- Говори как опытный коллега-закупщик: спокойно, по-деловому, на «вы», без канцелярита и без бодрого маркетинга.
- Пиши связным текстом. НЕ используй шаблон «Следующие шаги: 1. 2. 3.» в каждом ответе.
- Не начинай ответ с «По вашему запросу...». Продолжай разговор так, как его продолжил бы человек.
- 2–4 коротких абзаца. Никаких заголовков и таблиц.

Память диалога (turn_context):
- turn_context.kind говорит, что это за реплика: refine (уточнение), topic_switch (сменил тему), search (новый поиск), sort (пересортировка).
- Если added_fields непусто — коротко подтверди, что именно ты учёл («Понял, добавил бурый цвет»), и скажи, как это изменило выдачу (стало меньше/больше вариантов), если это видно из данных.
- Если dropped_fields непусто — подтверди, что снял это требование.
- Если kind = topic_switch — явно скажи, что переключился на новую категорию и что сбросил неподходящие параметры (switched_from). Не тащи старые размеры в новый запрос.
- НЕ перечисляй заново все известные параметры в каждом ответе. Покупатель их помнит — он их только что назвал.

Факты:
- Опирайся ТОЛЬКО на matches. Не выдумывай товары, поставщиков, цены, сроки, ИНН.
- score — процент соответствия ЗАЯВЛЕННЫМ требованиям, не «качество товара». Не преувеличивай.
- Если точных совпадений нет — скажи прямо и назови, чего не хватает (gaps).
- Если каталог маленький (catalog_stats.is_thin) — упомяни это как ограничение выбора, но не повторяй в каждом сообщении подряд.
- Если matches пуст, и это приветствие или вопрос о возможностях — просто поговори: ответь на вопрос и предложи описать задачу. Ничего не «находи».

В конце — максимум один естественный вопрос или предложение следующего шага, если он уместен. Если покупателю всё ясно, вопрос не нужен.
RULES;

        if ($json) {
            $rules .= "\n\nВерни JSON: {\"message\":\"...\"} с markdown-текстом.";
        } else {
            $rules .= "\n\nВерни только текст ответа в markdown, без JSON и без пояснений.";
        }

        $messages = [['role' => 'system', 'content' => $rules]];

        // Real dialogue turns, so the model can refer back naturally instead of
        // treating every message as the first one.
        foreach (array_slice($turn['history'] ?? [], -6) as $h) {
            $role = $h['role'] ?? '';
            if (! in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => mb_substr((string) ($h['content'] ?? ''), 0, 1200)];
        }

        $messages[] = [
            'role' => 'user',
            'content' => json_encode([
                'user_message' => $userMessage,
                'structured_query' => $query,
                'turn_context' => [
                    'kind' => $turn['turn_kind'] ?? null,
                    'added_fields' => $turn['added_fields'] ?? [],
                    'dropped_fields' => $turn['dropped_fields'] ?? [],
                    'switched_from' => $turn['switched_from'] ?? [],
                    'previous_result_count' => $turn['previous_result_count'] ?? null,
                    'current_result_count' => count($matches),
                ],
                'catalog_stats' => $stats,
                'matches' => $cards,
                'draft' => $template,
            ], JSON_UNESCAPED_UNICODE),
        ];

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     */
    /**
     * @return array{message: string, usage?: array}|null
     */
    private function polishWithLlm(
        string $template,
        array $query,
        array $matches,
        string $userMessage,
        array $stats,
        array $turn = [],
    ): ?array {
        $messages = $this->llmMessages($template, $query, $matches, $userMessage, $stats, $turn);
        $resp = $this->llm->chat($messages, json: true, temperature: 0.4);
        if ($resp === null) {
            return null;
        }
        $parsed = json_decode($resp['content'], true);
        $msg = is_array($parsed) ? ($parsed['message'] ?? null) : null;
        // Conversational replies are legitimately short ("Здравствуйте! ...").
        if (! is_string($msg) || mb_strlen(trim($msg)) < 10) {
            return null;
        }

        $usage = null;
        if (! empty($resp['usage'])) {
            $usage = LlmCost::fromUsage($resp['usage'], $resp['model'] ?? null);
        } else {
            $promptText = collect($messages)->pluck('content')->implode("\n");
            $usage = LlmCost::estimateFromText($promptText, $msg, $resp['model'] ?? null);
        }
        $usage['label'] = 'answer_compose';

        return ['message' => $msg, 'usage' => $usage];
    }
}
