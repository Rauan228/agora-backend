<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use App\Models\Offer;
use App\Models\Supplier;
use App\Services\Ai\AiMatchingService;
use App\Services\Ai\AnswerComposer;
use App\Services\Ai\LlmCost;
use App\Services\Ai\WaveSpeedClient;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only AI matching — same engine as public, but returns cost meter.
 * Never used by the product frontend.
 */
class AiSessionController extends Controller
{
    public function __construct(
        private AiMatchingService $ai,
        private AnswerComposer $composer,
        private WaveSpeedClient $llm,
    ) {}

    public function store(Request $request)
    {
        $session = $this->ai->createSession(
            $request->string('client_key')->toString() ?: 'admin'
        );

        $stats = $this->catalogStats();

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'welcome' => $this->welcomeText($stats),
            'catalog' => $stats,
            'session_cost' => $this->ai->sessionCostPayload($session),
            'cost_rates' => LlmCost::rates() + [
                'usd_to_rub' => (float) config('services.wavespeed.usd_to_rub', 90),
            ],
            'suggested_replies' => [
                'Гофрокороба для e-com, Москва',
                'Самосбор 400×300×200 мм, 5000 шт',
                'Гофролист Т-23 оптом',
                'Стрейч-плёнка машинная',
            ],
        ], 201);
    }

    public function catalog()
    {
        return response()->json($this->catalogStats() + [
            'cost_rates' => LlmCost::rates() + [
                'usd_to_rub' => (float) config('services.wavespeed.usd_to_rub', 90),
            ],
        ]);
    }

    public function show(string $session)
    {
        $model = AiSession::with('messages')->findOrFail($session);

        return response()->json([
            'session_id' => $model->id,
            'status' => $model->status,
            'structured_query' => $model->structured_query,
            'understood' => $this->ai->understood($model->structured_query ?? []),
            'last_match_ids' => $model->last_match_ids,
            'session_cost' => $this->ai->sessionCostPayload($model),
            'messages' => $model->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'meta' => $m->meta,
                'cost' => is_array($m->meta) ? ($m->meta['cost'] ?? null) : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function message(Request $request, string $session)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $model = $this->activeSession($session);
        $result = $this->ai->handleMessage($model, $data['message'], includeCost: true);

        return response()->json($result);
    }

    /**
     * Removes one or more constraints and re-runs the match — the "×" on a
     * chip in the UI. No LLM call: this is a deterministic edit.
     */
    public function refine(Request $request, string $session)
    {
        $data = $request->validate([
            'remove' => ['required', 'array', 'min:1'],
            'remove.*' => ['string', 'max:40'],
        ]);

        $model = $this->activeSession($session);
        $result = $this->ai->refine($model, $data['remove'], includeCost: true);

        return response()->json($result);
    }

    public function stream(Request $request, string $session): StreamedResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $model = $this->activeSession($session);
        $userMessage = $data['message'];

        $response = new StreamedResponse(function () use ($model, $userMessage) {
            $emit = function (string $event, array $payload): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $emit('stage', ['stage' => 'intent', 'label' => 'Разбираю запрос']);
                $prepared = $this->ai->prepare($model, $userMessage);

                $emit('understood', [
                    'understood' => $this->ai->understood($prepared['query']),
                    'structured_query' => $prepared['query'],
                    'intent_source' => $prepared['intent_source'],
                ]);

                $emit('stage', ['stage' => 'match', 'label' => 'Ищу в каталоге']);
                $partial = $this->ai->finalizePreview($prepared);
                $emit('results', $partial);

                $emit('stage', ['stage' => 'compose', 'label' => 'Готовлю объяснение']);

                $streamMessages = $this->composer->streamMessages(
                    $prepared['query'],
                    $prepared['matches'],
                    $userMessage,
                    $prepared['stats'],
                    $prepared,
                );

                $text = null;
                $llmUsed = false;
                $composeUsage = null;

                if ($streamMessages !== null) {
                    $streamed = $this->llm->streamChat(
                        $streamMessages,
                        function (string $delta) use ($emit) {
                            $emit('delta', ['text' => $delta]);
                        },
                        temperature: 0.4,
                    );
                    if (is_array($streamed) && ! empty($streamed['content'])) {
                        $text = $streamed['content'];
                        $llmUsed = mb_strlen(trim($text)) >= 10;
                        if ($llmUsed) {
                            if (! empty($streamed['usage'])) {
                                $composeUsage = LlmCost::fromUsage($streamed['usage'], $streamed['model'] ?? null);
                            } else {
                                $promptText = collect($streamMessages)->pluck('content')->implode("\n");
                                $composeUsage = LlmCost::estimateFromText($promptText, $text, $streamed['model'] ?? null);
                            }
                            $composeUsage['label'] = 'answer_stream';
                        }
                    }
                }

                if (! $llmUsed) {
                    $text = $this->composer->template(
                        $prepared['query'],
                        $prepared['matches'],
                        $prepared['stats'],
                        $prepared,
                    );
                    $emit('delta', ['text' => $text, 'replace' => true]);
                }

                $final = $this->ai->finalize(
                    $model,
                    $prepared,
                    (string) $text,
                    $this->composer->repliesFor($prepared['query'], $prepared['matches'], $prepared),
                    $llmUsed,
                    $composeUsage,
                    includeCost: true,
                );

                $emit('done', $final);
            } catch (\Throwable $e) {
                report($e);
                $emit('error', ['message' => 'Не удалось обработать запрос. Попробуйте ещё раз.']);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function handoff(Request $request, string $session)
    {
        $data = $request->validate([
            'contact' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $model = AiSession::findOrFail($session);
        $result = $this->ai->handoff(
            $model,
            $data['contact'] ?? null,
            $data['note'] ?? null
        );
        $result['session_cost'] = $this->ai->sessionCostPayload($model->fresh());

        return response()->json($result);
    }

    private function activeSession(string $session): AiSession
    {
        $model = AiSession::findOrFail($session);
        if ($model->status === 'closed') {
            throw ValidationException::withMessages(['session' => 'Session closed']);
        }

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogStats(): array
    {
        $offers = Offer::query()
            ->where('offers.is_active', true)
            ->whereHas('supplier', fn ($s) => $s->where('suppliers.is_active', true));

        $total = $offers->clone()->count();

        $byCategory = $offers->clone()
            ->join('categories', 'categories.id', '=', 'offers.category_id')
            ->groupBy('categories.name')
            ->selectRaw('categories.name as category_name, count(offers.id) as offers_count')
            ->orderByDesc('offers_count')
            ->limit(8)
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->category_name => (int) $row->offers_count])
            ->all();

        return [
            'active_offers' => $total,
            'active_suppliers' => Supplier::query()->where('is_active', true)->count(),
            'categories' => $byCategory,
            'is_thin' => $total < 30,
            'llm_enabled' => $this->llm->enabled(),
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function welcomeText(array $stats): string
    {
        $total = (int) ($stats['active_offers'] ?? 0);
        $suppliers = (int) ($stats['active_suppliers'] ?? 0);

        if ($total === 0) {
            return 'В каталоге пока нет активных офферов.';
        }

        return 'Admin AI-тест. Каталог: '.$total.' офферов, '.$suppliers.' поставщиков. '
            .'Стоимость LLM показывается только здесь (не на витрине).';
    }
}
