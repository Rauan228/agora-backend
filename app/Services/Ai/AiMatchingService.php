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
     * Everything except the assistant prose — shared by the blocking and the
     * streaming endpoint so both see identical matches.
     *
     * @return array{
     *     query: array<string, mixed>,
     *     intent_source: string,
     *     matches: list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>,
     *     stats: array<string, mixed>,
     *     sort_mode: ?string
     * }
     */
    public function prepare(AiSession $session, string $userMessage, bool $persistUserMessage = true): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            abort(422, 'message empty');
        }

        if ($persistUserMessage) {
            AiMessage::create([
                'ai_session_id' => $session->id,
                'role' => 'user',
                'content' => $userMessage,
            ]);
        }

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

        $sortMode = $this->sortIntent($userMessage);
        $lastIds = $session->last_match_ids ?? [];

        if ($sortMode !== null && $lastIds !== []) {
            // Re-rank what the buyer is already looking at, keeping real scores.
            $result = $this->resortExisting($lastIds, $query, $sortMode);
        } else {
            $result = $this->matcher->search($query, limit: 8);
        }

        return [
            'query' => $query,
            'intent_source' => $parsed['source'],
            'matches' => $result['matches'],
            'stats' => $result['stats'],
            'sort_mode' => $sortMode,
        ];
    }

    /**
     * Match results without the assistant prose — emitted early while the text
     * is still streaming, so the results panel does not wait on the LLM.
     *
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    public function finalizePreview(array $prepared): array
    {
        $matches = $prepared['matches'];

        return [
            'structured_query' => $prepared['query'],
            'understood' => $this->understood($prepared['query']),
            'intent_source' => $prepared['intent_source'],
            'catalog_stats' => $prepared['stats'],
            'offers' => $this->serializeMatches($matches),
            'suppliers' => $this->uniqueSuppliers($matches),
            'comparison' => $this->buildComparison($matches),
        ];
    }

    /**
     * Persists the assistant turn and builds the API payload.
     *
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    public function finalize(
        AiSession $session,
        array $prepared,
        string $assistantMessage,
        array $suggestedReplies,
        bool $llmUsed,
    ): array {
        $query = $prepared['query'];
        $matches = $prepared['matches'];
        $stats = $prepared['stats'];

        $offerIds = array_map(fn ($r) => $r['offer']->id, $matches);
        $session->structured_query = $query;
        $session->last_match_ids = $offerIds;
        $session->save();

        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $assistantMessage,
            'meta' => [
                'structured_query' => $query,
                'intent_source' => $prepared['intent_source'],
                'offer_ids' => $offerIds,
                'catalog_stats' => $stats,
                'sort_mode' => $prepared['sort_mode'],
                'scores' => array_map(fn ($r) => [
                    'offer_id' => $r['offer']->id,
                    'score' => $r['score'],
                    'tier' => $r['tier'],
                    'reasons' => $r['reasons'],
                    'gaps' => $r['gaps'],
                ], $matches),
                'llm_used' => $llmUsed,
            ],
        ]);

        return [
            'session_id' => $session->id,
            'assistant_message' => $assistantMessage,
            'structured_query' => $query,
            'understood' => $this->understood($query),
            'intent_source' => $prepared['intent_source'],
            'catalog_stats' => $stats,
            'offers' => $this->serializeMatches($matches),
            'suppliers' => $this->uniqueSuppliers($matches),
            'comparison' => $this->buildComparison($matches),
            'suggested_replies' => $suggestedReplies,
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
    public function handleMessage(AiSession $session, string $userMessage): array
    {
        $prepared = $this->prepare($session, $userMessage);

        $answer = $this->composer->compose(
            $prepared['query'],
            $prepared['matches'],
            $userMessage,
            $prepared['stats'],
        );

        return $this->finalize(
            $session,
            $prepared,
            $answer['message'],
            $answer['suggested_replies'],
            $answer['llm_used'],
        );
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

    /**
     * Human-readable summary of what the AI extracted — the trust anchor.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{label: string, value: string, key: string}>
     */
    public function understood(array $query): array
    {
        $out = [];

        $slugs = $query['category_slugs'] ?? [];
        if (is_array($slugs) && $slugs !== []) {
            $names = collect(config('agora.categories', []))
                ->whereIn('slug', $slugs)
                ->pluck('name')
                ->all();
            $out[] = [
                'key' => 'category',
                'label' => 'Категория',
                'value' => implode(', ', $names !== [] ? $names : $slugs),
            ];
        }

        if (! empty($query['box_type'])) {
            $out[] = ['key' => 'box_type', 'label' => 'Тип', 'value' => (string) $query['box_type']];
        }

        $l = $query['length_mm'] ?? null;
        $w = $query['width_mm'] ?? null;
        $h = $query['height_mm'] ?? null;
        if ($l || $w || $h) {
            $dims = array_filter([$l, $w, $h]);
            $out[] = [
                'key' => 'dimensions',
                'label' => 'Размер',
                'value' => implode('×', $dims).' мм'
                    .' (±'.(int) ($query['size_tolerance_pct'] ?? 10).'%)',
            ];
        }

        if (! empty($query['qty'])) {
            $out[] = ['key' => 'qty', 'label' => 'Объём', 'value' => number_format((int) $query['qty'], 0, '.', ' ').' шт'];
        }
        if (! empty($query['board_grade'])) {
            $out[] = ['key' => 'board_grade', 'label' => 'Марка', 'value' => (string) $query['board_grade']];
        }
        if (! empty($query['flute_profile'])) {
            $out[] = ['key' => 'flute_profile', 'label' => 'Профиль', 'value' => (string) $query['flute_profile']];
        }
        if (! empty($query['liner_color'])) {
            $out[] = ['key' => 'liner_color', 'label' => 'Цвет', 'value' => (string) $query['liner_color']];
        }
        if (($query['print_needed'] ?? null) === true) {
            $out[] = ['key' => 'print_needed', 'label' => 'Печать', 'value' => 'нужна'];
        }
        if (! empty($query['city']) || ($query['delivery_moscow'] ?? null) === true) {
            $out[] = [
                'key' => 'city',
                'label' => 'Гео',
                'value' => (string) ($query['city'] ?? 'Москва / МО'),
            ];
        }
        if (! empty($query['lead_time_days_max'])) {
            $out[] = ['key' => 'lead_time', 'label' => 'Срок', 'value' => 'до '.(int) $query['lead_time_days_max'].' дн.'];
        }
        if (! empty($query['moq_max'])) {
            $out[] = ['key' => 'moq_max', 'label' => 'MOQ не выше', 'value' => (string) (int) $query['moq_max']];
        }

        return $out;
    }

    /** 'price' | 'lead' | 'score' | null */
    private function sortIntent(string $msg): ?string
    {
        if (preg_match('/дешевл|подешев|ниже цен|по цене/ui', $msg)) {
            return 'price';
        }
        if (preg_match('/быстрее|срочн|короче срок|по сроку/ui', $msg)) {
            return 'lead';
        }
        if (preg_match('/сравни|сравнени|топ\s*-?\s*3/ui', $msg)) {
            return 'score';
        }

        return null;
    }

    /**
     * Re-scores the current shortlist against the query, then orders it by the
     * requested dimension — so match_score and reasons stay truthful.
     *
     * @param  list<int|string>  $ids
     * @param  array<string, mixed>  $query
     * @return array{matches: list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>, stats: array<string, mixed>}
     */
    private function resortExisting(array $ids, array $query, string $mode): array
    {
        $full = $this->matcher->search($query, limit: 20);
        $byId = [];
        foreach ($full['matches'] as $row) {
            $byId[$row['offer']->id] = $row;
        }

        $rows = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $rows[] = $byId[$id];
            }
        }

        // Shortlist offers that no longer score (deactivated, etc.) are dropped;
        // if that empties the list, fall back to a fresh search.
        if ($rows === []) {
            return $full;
        }

        if ($mode === 'price') {
            usort($rows, fn ($a, $b) => $this->sortablePrice($a['offer']) <=> $this->sortablePrice($b['offer']));
        } elseif ($mode === 'lead') {
            usort($rows, fn ($a, $b) => $this->leadDays($a['offer']) <=> $this->leadDays($b['offer']));
        } else {
            usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);
        }

        $stats = $full['stats'];
        $stats['returned'] = count($rows);
        $stats['sorted_by'] = $mode;
        $stats['exact_count'] = count(array_filter($rows, fn ($r) => $r['tier'] === 'exact'));
        $stats['top_score'] = $rows === [] ? 0 : max(array_map(fn ($r) => $r['score'], $rows));

        return ['matches' => $rows, 'stats' => $stats];
    }

    private function sortablePrice(Offer $offer): float
    {
        if ($offer->price_hidden || $offer->price_value === null) {
            return PHP_FLOAT_MAX;
        }

        return (float) $offer->price_value;
    }

    private function leadDays(Offer $offer): int
    {
        $lead = ((int) ($offer->production_lead_days ?? 0)) + ((int) ($offer->delivery_lead_days ?? 0));

        return $lead > 0 ? $lead : PHP_INT_MAX;
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>
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
                $out[] = ['offer' => $offers[$id], 'score' => 0, 'reasons' => [], 'gaps' => [], 'tier' => 'unknown'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>  $matches
     * @return list<array<string, mixed>>
     */
    private function serializeMatches(array $matches): array
    {
        $out = [];
        foreach ($matches as $row) {
            $resource = (new OfferResource($row['offer']))->resolve();
            $resource['match_score'] = $row['score'];
            $resource['match_tier'] = $row['tier'];
            $resource['match_reasons'] = $row['reasons'];
            $resource['match_gaps'] = $row['gaps'];
            $out[] = $resource;
        }

        return $out;
    }

    /**
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>  $matches
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
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>  $matches
     * @return array<string, mixed>
     */
    private function buildComparison(array $matches): array
    {
        $rows = [];
        foreach (array_slice($matches, 0, 5) as $row) {
            $o = $row['offer'];
            $specs = $o->specs ?? [];
            $lead = ((int) ($o->production_lead_days ?? 0)) + ((int) ($o->delivery_lead_days ?? 0));
            $rows[] = [
                'offer_id' => $o->id,
                'title' => $o->offer_title,
                'supplier' => $o->supplier?->commercial_name,
                'price' => $o->price_hidden ? null : (float) $o->price_value,
                'currency' => $o->currency,
                'price_basis' => $o->price_basis,
                'moq' => $o->moq_value,
                'stock_status' => $o->stock_status,
                'lead_days' => $lead > 0 ? $lead : null,
                'box_type' => $specs['box_type'] ?? $specs['type'] ?? null,
                'size_mm' => $this->sizeLabel($specs),
                'board_grade' => $specs['box_board_grade'] ?? $specs['board_grade'] ?? $specs['grade'] ?? null,
                'match_score' => $row['score'],
                'match_tier' => $row['tier'],
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
        $l = $specs['box_inner_length_mm'] ?? $specs['inner_length_mm'] ?? $specs['sheet_length_mm'] ?? $specs['length_mm'] ?? null;
        $w = $specs['box_inner_width_mm'] ?? $specs['inner_width_mm'] ?? $specs['sheet_width_mm'] ?? $specs['width_mm'] ?? null;
        $h = $specs['box_inner_height_mm'] ?? $specs['inner_height_mm'] ?? $specs['height_mm'] ?? null;
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
     * @param  list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>  $matches
     */
    private function briefText(array $query, array $matches): string
    {
        $lines = ['Бриф AI-подбора Agora'];

        foreach ($this->understood($query) as $item) {
            $lines[] = $item['label'].': '.$item['value'];
        }

        if (count($lines) === 1) {
            $lines[] = '(параметры не уточнены — запрос был общий)';
        }

        if ($matches !== []) {
            $lines[] = '';
            $lines[] = 'Shortlist:';
            foreach (array_slice($matches, 0, 5) as $i => $row) {
                $o = $row['offer'];
                $score = $row['score'] > 0 ? ' — '.$row['score'].'%' : '';
                $lines[] = ($i + 1).'. #'.$o->id.' '.$o->offer_title
                    .' / '.($o->supplier?->commercial_name ?? '—').$score;
                if (! empty($row['gaps'])) {
                    $lines[] = '   расхождения: '.implode('; ', array_slice($row['gaps'], 0, 3));
                }
            }
        } else {
            $lines[] = '';
            $lines[] = 'Shortlist пуст — в каталоге нет подходящих офферов, нужен поиск поставщика.';
        }

        return implode("\n", $lines);
    }
}
