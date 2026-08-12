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
        private TurnInterpreter $interpreter,
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

        $previous = $session->structured_query ?? $this->intentParser->emptyQuery();
        $lastIds = $session->last_match_ids ?? [];
        $hadPriorSearch = $lastIds !== [] || $this->interpreter->hasAnyConstraint($previous);

        // Decide what this turn means for the conversation *before* parsing, so
        // a topic switch can clear stale constraints instead of inheriting them.
        $turn = $this->interpreter->interpret(
            $userMessage,
            $previous,
            $hadPriorSearch,
            $this->intentParser,
        );

        $baseQuery = $turn['base_query'];

        // Small talk / meta: answer conversationally, keep the running query,
        // and don't spend an LLM call or a match on it.
        if (! $turn['should_match']) {
            return [
                'query' => $this->intentParser->normalize($baseQuery),
                'intent_source' => 'conversation',
                'matches' => [],
                'stats' => $this->matcher->emptyStats(),
                'sort_mode' => null,
                'intent_usage' => null,
                'turn_kind' => $turn['kind'],
                'added_fields' => [],
                'dropped_fields' => $turn['dropped'],
                'switched_from' => $turn['switched_from'],
                'catalog' => $this->catalogSnapshot(),
                'should_match' => false,
                'history' => $history,
                'previous_result_count' => count($lastIds),
            ];
        }

        $parsed = $this->intentParser->parse($userMessage, $baseQuery, $history);
        $query = $parsed['query'];

        // A drop must survive parsing: the LLM sees the old value in history and
        // happily re-adds it, so re-apply the removals afterwards.
        foreach ($turn['dropped'] as $field) {
            $query[$field] = null;
        }
        foreach ($turn['switched_from'] as $field) {
            if (empty($this->intentParser->heuristic($userMessage)[$field] ?? null)) {
                $query[$field] = null;
            }
        }
        $query = $this->intentParser->normalize($query);

        $sortMode = $turn['kind'] === TurnInterpreter::KIND_SORT
            ? $this->sortIntent($userMessage)
            : null;

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
            'intent_usage' => $parsed['usage'] ?? null,
            'turn_kind' => $turn['kind'],
            'added_fields' => $this->interpreter->addedFields($previous, $query),
            'dropped_fields' => $turn['dropped'],
            'switched_from' => $turn['switched_from'],
            'catalog' => $this->catalogSnapshot(),
            'should_match' => true,
            'history' => $history,
            'previous_result_count' => count($lastIds),
        ];
    }

    /**
     * Applies a manual edit from the UI (removing one constraint chip) and
     * re-runs the match without going through the LLM at all.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function applyEdit(AiSession $session, array $fields): array
    {
        $query = $session->structured_query ?? $this->intentParser->emptyQuery();
        $previous = $query;

        $cleared = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $this->intentParser->emptyQuery())) {
                continue;
            }
            if (! empty($query[$field]) || $query[$field] === true) {
                $cleared[] = $field;
            }
            $query[$field] = $field === 'category_slugs' || $field === 'keywords' ? [] : null;
        }

        // Dropping the size means dropping all three dimensions together.
        if (in_array('dimensions', $fields, true)) {
            foreach (['length_mm', 'width_mm', 'height_mm'] as $d) {
                if (! empty($query[$d])) {
                    $cleared[] = $d;
                }
                $query[$d] = null;
            }
        }

        $query = $this->intentParser->normalize($query);
        $result = $this->matcher->search($query, limit: 8);

        return [
            'query' => $query,
            'intent_source' => 'manual_edit',
            'matches' => $result['matches'],
            'stats' => $result['stats'],
            'sort_mode' => null,
            'intent_usage' => null,
            'turn_kind' => TurnInterpreter::KIND_REFINE,
            'added_fields' => [],
            'dropped_fields' => array_values(array_unique($cleared)),
            'switched_from' => [],
            'catalog' => $this->catalogSnapshot(),
            'previous_query' => $previous,
        ];
    }

    /**
     * Catalog scale, so the composer can be honest without a second query.
     *
     * @return array<string, mixed>
     */
    private function catalogSnapshot(): array
    {
        $total = Offer::query()
            ->where('offers.is_active', true)
            ->whereHas('supplier', fn ($s) => $s->where('suppliers.is_active', true))
            ->count();

        return ['active_offers' => $total, 'is_thin' => $total < 30];
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
            // The UI needs this early: a conversational turn must not blank the
            // results panel while the reply is still streaming.
            'turn' => [
                'kind' => $prepared['turn_kind'] ?? null,
                'added_fields' => $prepared['added_fields'] ?? [],
                'dropped_fields' => $prepared['dropped_fields'] ?? [],
                'switched_from' => $prepared['switched_from'] ?? [],
                'searched' => ($prepared['should_match'] ?? true) === true,
            ],
        ];
    }

    /**
     * Persists the assistant turn and builds the API payload.
     *
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>|null  $composeUsage  from AnswerComposer / stream
     * @return array<string, mixed>
     */
    public function finalize(
        AiSession $session,
        array $prepared,
        string $assistantMessage,
        array $suggestedReplies,
        bool $llmUsed,
        ?array $composeUsage = null,
        bool $includeCost = false,
    ): array {
        $query = $prepared['query'];
        $matches = $prepared['matches'];
        $stats = $prepared['stats'];

        $parts = [];
        if (! empty($prepared['intent_usage'])) {
            $parts[] = $prepared['intent_usage'];
        }
        if (! empty($composeUsage)) {
            $parts[] = $composeUsage;
        }
        $turnCost = LlmCost::mergeParts($parts);
        $turnCost['match_search_usd'] = 0.0;
        $turnCost['match_search_note'] = 'Catalog search is free (SQL scoring on server)';

        $offerIds = array_map(fn ($r) => $r['offer']->id, $matches);
        $session->structured_query = $query;
        $session->last_match_ids = $offerIds;

        // Aggregate session totals (admin meter)
        $session->tokens_in = (int) ($session->tokens_in ?? 0) + (int) $turnCost['prompt_tokens'];
        $session->tokens_out = (int) ($session->tokens_out ?? 0) + (int) $turnCost['completion_tokens'];
        $session->cost_usd = round((float) ($session->cost_usd ?? 0) + (float) $turnCost['cost_usd'], 8);
        $session->llm_calls = (int) ($session->llm_calls ?? 0) + (int) $turnCost['llm_calls'];
        $prevSummary = is_array($session->cost_summary) ? $session->cost_summary : LlmCost::emptySession();
        $session->cost_summary = LlmCost::addSessionTotals($prevSummary, [
            'prompt_tokens' => $turnCost['prompt_tokens'],
            'completion_tokens' => $turnCost['completion_tokens'],
            'total_tokens' => $turnCost['total_tokens'],
            'cost_usd' => $turnCost['cost_usd'],
            'llm_calls' => $turnCost['llm_calls'],
            'messages_with_llm' => $llmUsed || $turnCost['llm_calls'] > 0 ? 1 : 0,
            'user_messages' => 1,
        ]);
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
                'cost' => $turnCost, // stored; only admin API exposes it
            ],
        ]);

        $payload = [
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
            'turn' => [
                'kind' => $prepared['turn_kind'] ?? null,
                'added_fields' => $prepared['added_fields'] ?? [],
                'dropped_fields' => $prepared['dropped_fields'] ?? [],
                'switched_from' => $prepared['switched_from'] ?? [],
                'searched' => ($prepared['should_match'] ?? true) === true,
            ],
            'cta' => [
                'type' => 'request_quote',
                'label' => 'Передать менеджеру',
                'prefill' => [
                    'session_id' => $session->id,
                    'brief' => $this->briefText($query, $matches),
                ],
            ],
        ];

        if ($includeCost) {
            $payload['cost'] = $turnCost;
            $payload['session_cost'] = $this->sessionCostPayload($session);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionCostPayload(AiSession $session): array
    {
        $summary = is_array($session->cost_summary) ? $session->cost_summary : LlmCost::emptySession();
        $usd = (float) ($session->cost_usd ?? $summary['cost_usd'] ?? 0);
        $rub = round($usd * (float) config('services.wavespeed.usd_to_rub', 90), 4);

        return [
            'prompt_tokens' => (int) ($session->tokens_in ?? $summary['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($session->tokens_out ?? $summary['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($session->tokens_in ?? 0) + (int) ($session->tokens_out ?? 0),
            'cost_usd' => round($usd, 8),
            'cost_rub_approx' => $rub,
            'llm_calls' => (int) ($session->llm_calls ?? $summary['llm_calls'] ?? 0),
            'user_messages' => (int) ($summary['user_messages'] ?? 0),
            'messages_with_llm' => (int) ($summary['messages_with_llm'] ?? 0),
            'rates' => LlmCost::rates(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function handleMessage(AiSession $session, string $userMessage, bool $includeCost = false): array
    {
        $prepared = $this->prepare($session, $userMessage);

        $answer = $this->composer->compose(
            $prepared['query'],
            $prepared['matches'],
            $userMessage,
            $prepared['stats'],
            $prepared,
        );

        return $this->finalize(
            $session,
            $prepared,
            $answer['message'],
            $answer['suggested_replies'],
            $answer['llm_used'],
            $answer['usage'] ?? null,
            $includeCost,
        );
    }

    /**
     * Constraint removed from the UI: re-match deterministically and record the
     * turn in the transcript so the conversation stays coherent.
     *
     * @param  list<string>  $remove
     * @return array<string, mixed>
     */
    public function refine(AiSession $session, array $remove, bool $includeCost = false): array
    {
        $prepared = $this->applyEdit($session, $remove);

        $label = $this->composer->describeRemoval($prepared['dropped_fields']);
        AiMessage::create([
            'ai_session_id' => $session->id,
            'role' => 'user',
            'content' => $label !== null ? "Убрать из запроса: {$label}" : 'Изменить фильтры',
        ]);

        $message = $this->composer->template(
            $prepared['query'],
            $prepared['matches'],
            $prepared['stats'],
            $prepared,
        );

        return $this->finalize(
            $session,
            $prepared,
            $message,
            $this->composer->repliesFor($prepared['query'], $prepared['matches'], $prepared),
            llmUsed: false,
            composeUsage: null,
            includeCost: $includeCost,
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
     * Each entry carries `fields`: the query keys to clear when the buyer
     * removes that chip in the UI, so a tag is directly actionable.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{key: string, label: string, value: string, fields: list<string>, removable: bool}>
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
                'fields' => ['category_slugs'],
                // Dropping the category turns the search into "anything" — allow
                // it, but it is rarely what the buyer wants.
                'removable' => true,
            ];
        }

        if (! empty($query['box_type'])) {
            $out[] = [
                'key' => 'box_type',
                'label' => 'Тип',
                'value' => (string) $query['box_type'],
                'fields' => ['box_type'],
                'removable' => true,
            ];
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
                'fields' => ['length_mm', 'width_mm', 'height_mm'],
                'removable' => true,
            ];
        }

        if (! empty($query['qty'])) {
            $out[] = [
                'key' => 'qty',
                'label' => 'Объём',
                'value' => number_format((int) $query['qty'], 0, '.', ' ').' шт',
                'fields' => ['qty'],
                'removable' => true,
            ];
        }
        if (! empty($query['board_grade'])) {
            $out[] = [
                'key' => 'board_grade',
                'label' => 'Марка',
                'value' => (string) $query['board_grade'],
                'fields' => ['board_grade'],
                'removable' => true,
            ];
        }
        if (! empty($query['flute_profile'])) {
            $out[] = [
                'key' => 'flute_profile',
                'label' => 'Профиль',
                'value' => (string) $query['flute_profile'],
                'fields' => ['flute_profile'],
                'removable' => true,
            ];
        }
        if (! empty($query['liner_color'])) {
            $out[] = [
                'key' => 'liner_color',
                'label' => 'Цвет',
                'value' => (string) $query['liner_color'],
                'fields' => ['liner_color'],
                'removable' => true,
            ];
        }
        if (($query['print_needed'] ?? null) === true) {
            $out[] = [
                'key' => 'print_needed',
                'label' => 'Печать',
                'value' => 'нужна',
                'fields' => ['print_needed', 'branding_needed'],
                'removable' => true,
            ];
        }
        if (! empty($query['city']) || ($query['delivery_moscow'] ?? null) === true) {
            $out[] = [
                'key' => 'city',
                'label' => 'Гео',
                'value' => (string) ($query['city'] ?? 'Москва / МО'),
                'fields' => ['city', 'delivery_moscow'],
                'removable' => true,
            ];
        }
        if (! empty($query['lead_time_days_max'])) {
            $out[] = [
                'key' => 'lead_time',
                'label' => 'Срок',
                'value' => 'до '.(int) $query['lead_time_days_max'].' дн.',
                'fields' => ['lead_time_days_max'],
                'removable' => true,
            ];
        }
        if (! empty($query['moq_max'])) {
            $out[] = [
                'key' => 'moq_max',
                'label' => 'MOQ не выше',
                'value' => (string) (int) $query['moq_max'],
                'fields' => ['moq_max'],
                'removable' => true,
            ];
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
