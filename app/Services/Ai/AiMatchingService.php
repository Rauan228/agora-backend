<?php

namespace App\Services\Ai;

use App\Http\Resources\OfferResource;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Offer;
use Illuminate\Support\Str;

class AiMatchingService
{
    public function __construct(
        private IntentParser $intentParser,
        private OfferMatcher $matcher,
        private AnswerComposer $composer,
    ) {}

    public function createSession(?string $clientKey = null): AiSession
    {
        return AiSession::create([
            'id' => (string) Str::uuid(),
            'client_key' => $clientKey,
            'status' => 'active',
            'structured_query' => $this->intentParser->emptyQuery(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleMessage(AiSession $session, string $userMessage): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            abort(422, 'message empty');
        }

        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $history = $session->messages()
            ->orderBy('id')
            ->get()
            ->map(fn (AiMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $parsed = $this->intentParser->parse(
            $userMessage,
            $session->structured_query,
            $history
        );
        $query = $parsed['query'];

        // Special intents on shortlist
        if ($this->wantsCheaper($userMessage) && ! empty($session->last_match_ids)) {
            $matches = $this->resortExisting($session->last_match_ids, 'price');
        } elseif ($this->wantsCompare($userMessage) && ! empty($session->last_match_ids)) {
            $matches = $this->resortExisting($session->last_match_ids, 'score');
        } else {
            $matches = $this->matcher->match($query, limit: 8);
        }

        $answer = $this->composer->compose($query, $matches, $userMessage);

        $offerIds = array_map(fn ($r) => $r['offer']->id, $matches);
        $session->structured_query = $query;
        $session->last_match_ids = $offerIds;
        $session->save();

        $meta = [
            'structured_query' => $query,
            'intent_source' => $parsed['source'],
            'intent_model' => $parsed['model'] ?? null,
            'offer_ids' => $offerIds,
            'scores' => array_map(fn ($r) => [
                'offer_id' => $r['offer']->id,
                'score' => $r['score'],
                'reasons' => $r['reasons'],
            ], $matches),
            'llm_used' => $answer['llm_used'],
        ];

        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $answer['message'],
            'meta' => $meta,
        ]);

        return [
            'session_id' => $session->id,
            'assistant_message' => $answer['message'],
            'structured_query' => $query,
            'intent_source' => $parsed['source'],
            'offers' => $this->serializeMatches($matches),
            'suppliers' => $this->uniqueSuppliers($matches),
            'comparison' => $this->buildComparison($matches),
            'suggested_replies' => $answer['suggested_replies'],
            'cta' => [
                'type' => 'request_quote',
                'label' => 'Передать менеджеру',
                'prefill' => [
                    'session_id' => $session->id,
                    'brief' => $this->briefText($query, $matches),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handoff(AiSession $session, ?string $contact, ?string $note): array
    {
        $session->status = 'handed_off';
        $session->handoff_contact = $contact;
        $session->handoff_note = $note;
        $session->handed_off_at = now();
        $session->save();

        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'system',
            'content' => 'Handoff to manager',
            'meta' => [
                'contact' => $contact,
                'note' => $note,
                'structured_query' => $session->structured_query,
                'offer_ids' => $session->last_match_ids,
            ],
        ]);

        return [
            'ok' => true,
            'session_id' => $session->id,
            'status' => $session->status,
            'brief' => $this->briefText(
                $session->structured_query ?? [],
                $this->loadMatchesByIds($session->last_match_ids ?? [])
            ),
            'structured_query' => $session->structured_query,
            'offer_ids' => $session->last_match_ids,
        ];
    }

    private function wantsCheaper(string $msg): bool
    {
        return (bool) preg_match('/дешевл|подешев|ниже цен/ui', $msg);
    }

    private function wantsCompare(string $msg): bool
    {
        return (bool) preg_match('/сравни|сравнени|топ\s*-?\s*3/ui', $msg);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{offer: Offer, score: int, reasons: list<string>}>
     */
    private function resortExisting(array $ids, string $mode): array
    {
        $offers = Offer::query()
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->with(['supplier', 'category'])
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($ids as $id) {
            if (! isset($offers[$id])) {
                continue;
            }
            $rows[] = [
                'offer' => $offers[$id],
                'score' => 50,
                'reasons' => ['Из текущего shortlist'],
            ];
        }

        if ($mode === 'price') {
            usort($rows, fn ($a, $b) => ((float) $a['offer']->price_value) <=> ((float) $b['offer']->price_value));
            foreach ($rows as $i => $_) {
                $rows[$i]['reasons'] = ['Сортировка: дешевле в shortlist'];
            }
        }

        return $rows;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{offer: Offer, score: int, reasons: list<string>}>
     */
    private function loadMatchesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $offers = Offer::query()->whereIn('id', $ids)->with(['supplier', 'category'])->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            if (isset($offers[$id])) {
                $out[] = ['offer' => $offers[$id], 'score' => 0, 'reasons' => []];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     * @return list<array<string, mixed>>
     */
    private function serializeMatches(array $matches): array
    {
        $out = [];
        foreach ($matches as $row) {
            $resource = (new OfferResource($row['offer']))->resolve();
            $resource['match_score'] = $row['score'];
            $resource['match_reasons'] = $row['reasons'];
            $out[] = $resource;
        }

        return $out;
    }

    /**
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     * @return list<array<string, mixed>>
     */
    private function uniqueSuppliers(array $matches): array
    {
        $seen = [];
        $out = [];
        foreach ($matches as $row) {
            $s = $row['offer']->supplier;
            if (! $s || isset($seen[$s->id])) {
                continue;
            }
            $seen[$s->id] = true;
            $out[] = [
                'id' => $s->id,
                'commercial_name' => $s->commercial_name,
                'logo_url' => $s->logo_url,
                'best_match_score' => $row['score'],
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     * @return array<string, mixed>
     */
    private function buildComparison(array $matches): array
    {
        $rows = [];
        foreach (array_slice($matches, 0, 5) as $row) {
            $o = $row['offer'];
            $specs = $o->specs ?? [];
            $rows[] = [
                'offer_id' => $o->id,
                'title' => $o->offer_title,
                'supplier' => $o->supplier?->commercial_name,
                'price' => $o->price_hidden ? null : (float) $o->price_value,
                'currency' => $o->currency,
                'moq' => $o->moq_value,
                'stock_status' => $o->stock_status,
                'lead_days' => ($o->production_lead_days ?? 0) + ($o->delivery_lead_days ?? 0),
                'box_type' => $specs['box_type'] ?? null,
                'size_mm' => $this->sizeLabel($specs),
                'board_grade' => $specs['box_board_grade'] ?? $specs['board_grade'] ?? null,
                'match_score' => $row['score'],
            ];
        }

        return [
            'dimensions' => ['price', 'moq', 'lead_days', 'box_type', 'size_mm', 'board_grade', 'match_score'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $specs
     */
    private function sizeLabel(array $specs): ?string
    {
        $l = $specs['box_inner_length_mm'] ?? $specs['sheet_length_mm'] ?? null;
        $w = $specs['box_inner_width_mm'] ?? $specs['sheet_width_mm'] ?? null;
        $h = $specs['box_inner_height_mm'] ?? null;
        if ($l && $w && $h) {
            return "{$l}×{$w}×{$h}";
        }
        if ($l && $w) {
            return "{$l}×{$w}";
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<array{offer: Offer, score: int, reasons: list<string>}>  $matches
     */
    private function briefText(array $query, array $matches): string
    {
        $lines = ['Бриф AI-подбора Agora'];
        if (! empty($query['category_slugs'])) {
            $lines[] = 'Категории: '.implode(', ', $query['category_slugs']);
        }
        if (! empty($query['box_type'])) {
            $lines[] = 'Тип: '.$query['box_type'];
        }
        if (! empty($query['length_mm'])) {
            $lines[] = sprintf(
                'Размер: %s×%s×%s мм',
                $query['length_mm'] ?? '?',
                $query['width_mm'] ?? '?',
                $query['height_mm'] ?? '?'
            );
        }
        if (! empty($query['qty'])) {
            $lines[] = 'Объём: '.$query['qty'].' шт';
        }
        if (! empty($query['city']) || $query['delivery_moscow'] === true) {
            $lines[] = 'Гео: '.($query['city'] ?? 'Москва');
        }
        if (! empty($query['print_needed'])) {
            $lines[] = 'Печать/лого: да';
        }
        $lines[] = 'Shortlist:';
        foreach (array_slice($matches, 0, 5) as $i => $row) {
            $o = $row['offer'];
            $lines[] = ($i + 1).'. #'.$o->id.' '.$o->offer_title.' / '.($o->supplier?->commercial_name ?? '');
        }

        return implode("\n", $lines);
    }
}
