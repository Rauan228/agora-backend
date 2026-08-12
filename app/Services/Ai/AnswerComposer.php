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
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     * @return array{message: string, suggested_replies: list<string>, llm_used: bool}
     */
    public function compose(array $query, array $matches, string $userMessage): array
    {
        $template = $this->template($query, $matches);
        $suggested = $this->suggestedReplies($query, $matches);

        if ($this->llm->enabled() && $matches !== []) {
            $polish = $this->polishWithLlm($template, $query, $matches, $userMessage);
            if ($polish) {
                return [
                    'message' => $polish,
                    'suggested_replies' => $suggested,
                    'llm_used' => true,
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
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     */
    private function template(array $query, array $matches): string
    {
        $parts = [];

        if (! empty($query['clarifying_question']) && count($matches) < 2 && ($query['confidence'] ?? 0) < 0.55) {
            $parts[] = $query['clarifying_question'];
            $parts[] = 'Могу сразу показать ближайшие варианты из каталога — или уточните параметры.';
        }

        if ($matches === []) {
            $parts[] = 'В активном каталоге пока нет точных совпадений. Уточните категорию, размеры (мм) или объём — или оставьте заявку менеджеру.';

            return implode("\n\n", $parts);
        }

        $n = count($matches);
        $parts[] = "Нашёл **{$n}** подходящих вариант(ов) в каталоге Agora:";

        foreach (array_slice($matches, 0, 5) as $i => $row) {
            /** @var Offer $o */
            $o = $row['offer'];
            $num = $i + 1;
            $price = $o->price_hidden ? 'цена по запросу' : ((float) $o->price_value).' '.$o->currency.'/'.$o->price_basis;
            $supplier = $o->supplier?->commercial_name ?? 'Поставщик';
            $why = $row['reasons'] !== [] ? implode('; ', array_slice($row['reasons'], 0, 3)) : 'по общему соответствию';
            $parts[] = "{$num}. **{$o->offer_title}** — {$supplier}. {$price}. Match {$row['score']}/100. Почему: {$why}.";
        }

        if (! empty($query['missing_slots'])) {
            $parts[] = 'Чтобы сузить выбор: '.$this->slotHint($query['missing_slots'][0]);
        } else {
            $parts[] = 'Могу сравнить топ-3, найти дешевле или передать запрос менеджеру с уже собранным брифом.';
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<string>  $slots
     */
    private function slotHint(string $slot): string
    {
        return match ($slot) {
            'length_mm', 'width_mm', 'height_mm' => 'укажите внутренний размер Д×Ш×В в мм.',
            'qty' => 'назовите объём (шт или в месяц).',
            'box_type' => 'уточните тип короба (самосбор / 4-клапан и т.д.).',
            default => 'добавьте ещё деталей по задаче.',
        };
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
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
        if ($query['print_needed'] !== true) {
            $out[] = 'Нужна печать логотипа';
        }
        if ($query['delivery_moscow'] !== true) {
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
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     */
    private function polishWithLlm(string $template, array $query, array $matches, string $userMessage): ?string
    {
        $cards = [];
        foreach (array_slice($matches, 0, 5) as $row) {
            $o = $row['offer'];
            $cards[] = [
                'id' => $o->id,
                'title' => $o->offer_title,
                'supplier' => $o->supplier?->commercial_name,
                'score' => $row['score'],
                'reasons' => $row['reasons'],
            ];
        }

        $messages = [
            [
                'role' => 'system',
                'content' => 'Ты консультант-закупщик упаковки Agora. Коротко, по-деловому, на русском. '
                    .'Не выдумывай товары вне списка. Не обещай сроки/цены сверх данных. '
                    .'Верни JSON: {"message":"..."} с markdown-текстом для чата (2–5 коротких абзацев).',
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'user_message' => $userMessage,
                    'structured_query' => $query,
                    'matches' => $cards,
                    'draft' => $template,
                ], JSON_UNESCAPED_UNICODE),
            ],
        ];

        $resp = $this->llm->chat($messages, json: true, temperature: 0.3);
        if ($resp === null) {
            return null;
        }
        $parsed = json_decode($resp['content'], true);
        $msg = is_array($parsed) ? ($parsed['message'] ?? null) : null;

        return is_string($msg) && mb_strlen($msg) > 20 ? $msg : null;
    }
}
