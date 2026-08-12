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
    public function compose(array $query, array $matches, string $userMessage, array $stats = []): array
    {
        $template = $this->template($query, $matches, $stats);
        $suggested = $this->suggestedReplies($query, $matches);

        if ($this->llm->enabled() && $matches !== []) {
            $polish = $this->polishWithLlm($template, $query, $matches, $userMessage, $stats);
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
    public function streamMessages(array $query, array $matches, string $userMessage, array $stats = []): ?array
    {
        if (! $this->llm->enabled() || $matches === []) {
            return null;
        }

        return $this->llmMessages(
            $this->template($query, $matches, $stats),
            $query,
            $matches,
            $userMessage,
            $stats,
            json: false,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @param  array<string, mixed>  $stats
     */
    public function template(array $query, array $matches, array $stats = []): string
    {
        $parts = [];
        $total = (int) ($stats['active_offers_total'] ?? 0);

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

        if (! empty($query['missing_slots'])) {
            $parts[] = 'Чтобы сузить выбор: '.$this->slotHint((string) $query['missing_slots'][0]);
        } else {
            $parts[] = 'Могу сравнить топ-3, отсортировать по цене или передать запрос менеджеру с готовым брифом.';
        }

        return implode("\n\n", $parts);
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
            'length_mm', 'width_mm', 'height_mm' => 'укажите внутренний размер Д×Ш×В в мм.',
            'qty' => 'назовите объём (шт разово или в месяц).',
            'box_type' => 'уточните тип короба (самосбор / 4-клапан и т.д.).',
            'city' => 'уточните город доставки.',
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
    public function repliesFor(array $query, array $matches): array
    {
        return $this->suggestedReplies($query, $matches);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps?: list<string>, tier?: string}>  $matches
     * @return list<string>
     */
    private function suggestedReplies(array $query, array $matches): array
    {
        $out = [];
        if (empty($query['length_mm'])) {
            $out[] = 'Размер 400×300×200 мм';
        }
        if (empty($query['qty'])) {
            $out[] = 'Нужно около 5000 шт/мес';
        }
        if (($query['print_needed'] ?? null) !== true) {
            $out[] = 'Нужна печать логотипа';
        }
        if (($query['delivery_moscow'] ?? null) !== true) {
            $out[] = 'Доставка в Москву';
        }
        if (count($matches) >= 2) {
            $out[] = 'Сравни топ-3';
            $out[] = 'Покажи дешевле';
        }
        $out[] = 'Передать менеджеру';

        return array_slice(array_values(array_unique($out)), 0, 4);
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

        $rules = 'Ты консультант-закупщик упаковки Agora. Пиши коротко, по-деловому, на русском. '
            .'Опирайся ТОЛЬКО на переданный список matches — не добавляй товары, поставщиков, цены и сроки от себя. '
            .'Если точных совпадений нет — скажи это прямо, без бодрого тона, и назови, чего не хватает (поле gaps). '
            .'Не преувеличивай качество матча: score — это процент соответствия заявленным требованиям. '
            .'Если каталог маленький (см. catalog_stats) — упомяни это как ограничение, а не как «мы нашли лучшее на рынке». '
            .'2–4 коротких абзаца, затем 1–3 следующих шага.';

        if ($json) {
            $rules .= ' Верни JSON: {"message":"..."} с markdown-текстом.';
        } else {
            $rules .= ' Верни только текст ответа в markdown, без JSON и без пояснений.';
        }

        return [
            ['role' => 'system', 'content' => $rules],
            [
                'role' => 'user',
                'content' => json_encode([
                    'user_message' => $userMessage,
                    'structured_query' => $query,
                    'catalog_stats' => $stats,
                    'matches' => $cards,
                    'draft' => $template,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];
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
    ): ?array {
        $messages = $this->llmMessages($template, $query, $matches, $userMessage, $stats);
        $resp = $this->llm->chat($messages, json: true, temperature: 0.3);
        if ($resp === null) {
            return null;
        }
        $parsed = json_decode($resp['content'], true);
        $msg = is_array($parsed) ? ($parsed['message'] ?? null) : null;
        if (! is_string($msg) || mb_strlen($msg) <= 20) {
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
