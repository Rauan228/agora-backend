<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Supplier;
use App\Services\Ai\LlmCost;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin home dashboard — catalog + AI cost/volume. Not for public storefront.
 */
class DashboardController extends Controller
{
    public function show(Request $request)
    {
        $days = max(1, min((int) $request->integer('days', 30), 365));

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'days' => $days,
            'catalog' => $this->catalog(),
            'ai' => $this->ai($days),
            'rates' => LlmCost::rates() + [
                'usd_to_rub' => (float) config('services.wavespeed.usd_to_rub', 90),
            ],
        ]);
    }

    /**
     * Paginated session ledger for the cost table.
     */
    public function sessions(Request $request)
    {
        $perPage = max(5, min((int) $request->integer('per_page', 20), 50));
        $days = max(1, min((int) $request->integer('days', 30), 365));
        $from = Carbon::now()->subDays($days)->startOfDay();

        $q = AiSession::query()
            ->withCount('messages')
            ->where('created_at', '>=', $from)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status'));
        }

        $page = $q->paginate($perPage);

        $rows = collect($page->items())->map(function (AiSession $s) {
            $usd = (float) ($s->cost_usd ?? 0);
            $rub = $usd * (float) config('services.wavespeed.usd_to_rub', 90);

            return [
                'id' => $s->id,
                'status' => $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
                'handed_off_at' => $s->handed_off_at?->toIso8601String(),
                'handoff_contact' => $s->handoff_contact,
                'messages_count' => (int) $s->messages_count,
                'tokens_in' => (int) ($s->tokens_in ?? 0),
                'tokens_out' => (int) ($s->tokens_out ?? 0),
                'tokens_total' => (int) ($s->tokens_in ?? 0) + (int) ($s->tokens_out ?? 0),
                'llm_calls' => (int) ($s->llm_calls ?? 0),
                'cost_usd' => round($usd, 8),
                'cost_rub' => round($rub, 4),
                'query_preview' => $this->queryPreview($s->structured_query ?? []),
            ];
        })->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        $offers = Offer::query();
        $total = (clone $offers)->count();
        $active = (clone $offers)->where('is_active', true)->count();
        $inactive = $total - $active;
        $withPhoto = (clone $offers)->whereNotNull('photo_path')->where('photo_path', '!=', '')->count();
        $priceHidden = (clone $offers)->where('price_hidden', true)->count();
        $inStock = (clone $offers)->where('stock_status', 'В наличии')->count();

        $suppliersTotal = Supplier::query()->count();
        $suppliersActive = Supplier::query()->where('is_active', true)->count();

        $byCategory = Category::query()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Category $c) {
                $count = $c->offers()->count();
                $active = $c->offers()->where('is_active', true)->count();

                return [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'offers' => $count,
                    'active' => $active,
                ];
            })
            ->filter(fn ($r) => $r['offers'] > 0 || $r['active'] > 0)
            ->values()
            ->all();

        $completeness = $this->offerCompleteness();

        return [
            'offers_total' => $total,
            'offers_active' => $active,
            'offers_inactive' => $inactive,
            'offers_with_photo' => $withPhoto,
            'offers_without_photo' => max(0, $total - $withPhoto),
            'offers_price_hidden' => $priceHidden,
            'offers_in_stock' => $inStock,
            'suppliers_total' => $suppliersTotal,
            'suppliers_active' => $suppliersActive,
            'suppliers_inactive' => $suppliersTotal - $suppliersActive,
            'categories' => $byCategory,
            'is_thin' => $active < 30,
            'completeness' => $completeness,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offerCompleteness(): array
    {
        $offers = Offer::query()->where('is_active', true)->get(['id', 'specs', 'photo_path', 'description_short', 'moq_value']);
        $n = $offers->count();
        if ($n === 0) {
            return [
                'sample' => 0,
                'with_size' => 0,
                'with_photo' => 0,
                'with_description' => 0,
                'pct_size' => 0,
                'pct_photo' => 0,
                'pct_description' => 0,
            ];
        }

        $sizeKeys = [
            'box_inner_length_mm', 'inner_length_mm', 'length_mm',
            'sheet_length_mm', 'box_outer_length_mm',
        ];
        $withSize = 0;
        $withPhoto = 0;
        $withDesc = 0;
        foreach ($offers as $o) {
            $specs = is_array($o->specs) ? $o->specs : [];
            foreach ($sizeKeys as $k) {
                if (isset($specs[$k]) && $specs[$k] !== '' && $specs[$k] !== null) {
                    $withSize++;
                    break;
                }
            }
            if ($o->photo_path) {
                $withPhoto++;
            }
            if (filled($o->description_short)) {
                $withDesc++;
            }
        }

        return [
            'sample' => $n,
            'with_size' => $withSize,
            'with_photo' => $withPhoto,
            'with_description' => $withDesc,
            'pct_size' => round(100 * $withSize / $n),
            'pct_photo' => round(100 * $withPhoto / $n),
            'pct_description' => round(100 * $withDesc / $n),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ai(int $days): array
    {
        $from = Carbon::now()->subDays($days)->startOfDay();
        $today = Carbon::today();
        $week = Carbon::now()->subDays(7)->startOfDay();

        $all = AiSession::query();
        $inPeriod = AiSession::query()->where('created_at', '>=', $from);

        $totalAll = (clone $all)->count();
        $totalPeriod = (clone $inPeriod)->count();
        $todayCount = AiSession::query()->where('created_at', '>=', $today)->count();
        $weekCount = AiSession::query()->where('created_at', '>=', $week)->count();

        $byStatus = (clone $inPeriod)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $handoffs = (clone $inPeriod)->whereNotNull('handed_off_at')->count();
        $handoffsAll = AiSession::query()->whereNotNull('handed_off_at')->count();

        $tokensIn = (int) (clone $inPeriod)->sum('tokens_in');
        $tokensOut = (int) (clone $inPeriod)->sum('tokens_out');
        $costUsd = (float) (clone $inPeriod)->sum('cost_usd');
        $llmCalls = (int) (clone $inPeriod)->sum('llm_calls');

        $tokensInAll = (int) AiSession::query()->sum('tokens_in');
        $tokensOutAll = (int) AiSession::query()->sum('tokens_out');
        $costUsdAll = (float) AiSession::query()->sum('cost_usd');
        $llmCallsAll = (int) AiSession::query()->sum('llm_calls');

        $rub = (float) config('services.wavespeed.usd_to_rub', 90);

        $msgsPeriod = AiMessage::query()->where('created_at', '>=', $from);
        $msgTotal = (clone $msgsPeriod)->count();
        $msgUser = (clone $msgsPeriod)->where('role', 'user')->count();
        $msgAssistant = (clone $msgsPeriod)->where('role', 'assistant')->count();
        $msgAll = AiMessage::query()->count();
        $msgUserAll = AiMessage::query()->where('role', 'user')->count();

        $avgCost = $totalPeriod > 0 ? $costUsd / $totalPeriod : 0;
        $avgTokens = $totalPeriod > 0 ? ($tokensIn + $tokensOut) / $totalPeriod : 0;
        $avgMsgs = $totalPeriod > 0 ? $msgUser / $totalPeriod : 0;
        $avgCostPerMsg = $msgUser > 0 ? $costUsd / $msgUser : 0;

        $callBreakdown = $this->callBreakdown($from);

        return [
            'period_days' => $days,
            'sessions' => [
                'all_time' => $totalAll,
                'period' => $totalPeriod,
                'today' => $todayCount,
                'last_7_days' => $weekCount,
                'by_status' => [
                    'active' => (int) ($byStatus['active'] ?? 0),
                    'handed_off' => (int) ($byStatus['handed_off'] ?? 0),
                    'closed' => (int) ($byStatus['closed'] ?? 0),
                ],
            ],
            'handoffs' => [
                'period' => $handoffs,
                'all_time' => $handoffsAll,
            ],
            'messages' => [
                'period_total' => $msgTotal,
                'period_user' => $msgUser,
                'period_assistant' => $msgAssistant,
                'all_time' => $msgAll,
                'all_time_user' => $msgUserAll,
            ],
            'tokens' => [
                'period_in' => $tokensIn,
                'period_out' => $tokensOut,
                'period_total' => $tokensIn + $tokensOut,
                'all_time_in' => $tokensInAll,
                'all_time_out' => $tokensOutAll,
                'all_time_total' => $tokensInAll + $tokensOutAll,
            ],
            'cost' => [
                'period_usd' => round($costUsd, 8),
                'period_rub' => round($costUsd * $rub, 4),
                'all_time_usd' => round($costUsdAll, 8),
                'all_time_rub' => round($costUsdAll * $rub, 4),
                'avg_per_session_usd' => round($avgCost, 8),
                'avg_per_session_rub' => round($avgCost * $rub, 4),
                'avg_per_user_message_usd' => round($avgCostPerMsg, 8),
                'avg_per_user_message_rub' => round($avgCostPerMsg * $rub, 4),
                'match_search_usd' => 0,
            ],
            'llm_calls' => [
                'period' => $llmCalls,
                'all_time' => $llmCallsAll,
                'breakdown' => $callBreakdown,
            ],
            'averages' => [
                'tokens_per_session' => (int) round($avgTokens),
                'user_messages_per_session' => round($avgMsgs, 2),
            ],
            'daily' => $this->dailySeries($days),
        ];
    }

    /**
     * Sum cost by WaveSpeed call label from assistant message meta.
     *
     * @return list<array{label: string, calls: int, prompt_tokens: int, completion_tokens: int, cost_usd: float}>
     */
    private function callBreakdown(Carbon $from): array
    {
        $rows = AiMessage::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', $from)
            ->whereNotNull('meta')
            ->get(['meta']);

        $acc = [];
        foreach ($rows as $row) {
            $meta = is_array($row->meta) ? $row->meta : [];
            $calls = $meta['cost']['calls'] ?? [];
            if (! is_array($calls)) {
                continue;
            }
            foreach ($calls as $c) {
                $label = (string) ($c['label'] ?? 'other');
                if (! isset($acc[$label])) {
                    $acc[$label] = [
                        'label' => $label,
                        'calls' => 0,
                        'prompt_tokens' => 0,
                        'completion_tokens' => 0,
                        'cost_usd' => 0.0,
                    ];
                }
                $acc[$label]['calls']++;
                $acc[$label]['prompt_tokens'] += (int) ($c['prompt_tokens'] ?? 0);
                $acc[$label]['completion_tokens'] += (int) ($c['completion_tokens'] ?? 0);
                $acc[$label]['cost_usd'] += (float) ($c['cost_usd'] ?? 0);
            }
        }

        $out = array_values($acc);
        foreach ($out as &$r) {
            $r['cost_usd'] = round($r['cost_usd'], 8);
            $r['cost_rub'] = round($r['cost_usd'] * (float) config('services.wavespeed.usd_to_rub', 90), 4);
            $r['label_ru'] = match ($r['label']) {
                'intent_parse' => 'Разбор запроса',
                'answer_compose' => 'Текст ответа',
                'answer_stream' => 'Текст ответа (stream)',
                default => $r['label'],
            };
        }
        unset($r);

        usort($out, fn ($a, $b) => $b['cost_usd'] <=> $a['cost_usd']);

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailySeries(int $days): array
    {
        $start = Carbon::now()->subDays($days - 1)->startOfDay();
        $sessions = AiSession::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'cost_usd', 'tokens_in', 'tokens_out', 'llm_calls']);

        $bucket = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $bucket[$d] = [
                'date' => $d,
                'sessions' => 0,
                'cost_usd' => 0.0,
                'tokens' => 0,
                'llm_calls' => 0,
            ];
        }

        foreach ($sessions as $s) {
            $d = $s->created_at?->toDateString();
            if (! $d || ! isset($bucket[$d])) {
                continue;
            }
            $bucket[$d]['sessions']++;
            $bucket[$d]['cost_usd'] += (float) ($s->cost_usd ?? 0);
            $bucket[$d]['tokens'] += (int) ($s->tokens_in ?? 0) + (int) ($s->tokens_out ?? 0);
            $bucket[$d]['llm_calls'] += (int) ($s->llm_calls ?? 0);
        }

        return array_values(array_map(function ($r) {
            $r['cost_usd'] = round($r['cost_usd'], 8);
            $r['cost_rub'] = round($r['cost_usd'] * (float) config('services.wavespeed.usd_to_rub', 90), 4);

            return $r;
        }, $bucket));
    }

    /**
     * @param  array<string, mixed>  $q
     */
    private function queryPreview(array $q): string
    {
        $bits = [];
        $slugs = $q['category_slugs'] ?? [];
        if (is_array($slugs) && $slugs !== []) {
            $bits[] = implode(', ', $slugs);
        }
        if (! empty($q['length_mm'])) {
            $bits[] = $q['length_mm'].'×'.($q['width_mm'] ?? '?').'×'.($q['height_mm'] ?? '?');
        }
        if (! empty($q['liner_color'])) {
            $bits[] = (string) $q['liner_color'];
        }
        if (! empty($q['city'])) {
            $bits[] = (string) $q['city'];
        }
        if (! empty($q['qty'])) {
            $bits[] = $q['qty'].' шт';
        }

        return $bits !== [] ? implode(' · ', $bits) : '—';
    }
}
