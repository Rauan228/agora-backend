<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use App\Models\Offer;
use App\Models\Supplier;
use App\Services\Ai\AiMatchingService;
use App\Services\Ai\AnswerComposer;
use App\Services\Ai\LlmCost;
use App\Services\Ai\WaveSpeedClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public AI matching for product frontend (catalog-grounded).
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
        $this->throttle($request, 'ai-session-create', 30);

        $session = $this->ai->createSession(
            $request->string('client_key')->toString() ?: null
        );

        $stats = $this->catalogStats();

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'welcome' => $this->welcomeText($stats),
            'catalog' => $stats,
            'suggested_replies' => [
                'Гофрокороба для e-com, Москва',
                'Самосбор 400×300×200 мм, 5000 шт',
                'Гофролист Т-23 оптом',
                'Стрейч-плёнка машинная',
            ],
        ], 201);
    }

    /** Public catalog scale — lets the UI be honest about the search space. */
    public function catalog()
    {
        return response()->json($this->catalogStats());
    }

    public function show(string $session)
    {
        $model = AiSession::findOrFail($session);

        return response()->json($this->ai->presentSession($model, includeCost: false));
    }

    public function message(Request $request, string $session)
    {
        $this->throttle($request, 'ai-session-msg:'.$session, 40);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $model = $this->activeSession($session);

        $result = $this->ai->handleMessage($model, $data['message']);

        return response()->json($result);
    }

    /**
     * Removes one or more constraints and re-runs the match — the "×" on a
     * chip in the UI. No LLM call: this is a deterministic edit.
     */
    public function refine(Request $request, string $session)
    {
        $this->throttle($request, 'ai-session-msg:'.$session, 60);

        $data = $request->validate([
            'remove' => ['required', 'array', 'min:1'],
            'remove.*' => ['string', 'max:40'],
        ]);

        $model = $this->activeSession($session);

        return response()->json($this->ai->refine($model, $data['remove']));
    }

    /**
     * Server-sent events: matches land immediately, prose streams token by token.
     *
     * Frame types: `stage`, `understood`, `results`, `delta`, `done`, `error`.
     */
    public function stream(Request $request, string $session): StreamedResponse
    {
        $this->throttle($request, 'ai-session-msg:'.$session, 40);

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

                // Matches are deterministic — send them before any prose so the
                // right-hand panel fills in while the text is still streaming.
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
                        temperature: 0.3,
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
                    // No stream happened (or it failed) — deliver the fallback whole.
                    $emit('delta', ['text' => $text, 'replace' => true]);
                }

                // Public API: cost is stored server-side but NOT returned.
                $final = $this->ai->finalize(
                    $model,
                    $prepared,
                    (string) $text,
                    $this->composer->repliesFor($prepared['query'], $prepared['matches'], $prepared),
                    $llmUsed,
                    $composeUsage,
                    includeCost: false,
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
        // Tell nginx not to buffer — without this the whole stream arrives at once.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function handoff(Request $request, string $session)
    {
        $this->throttle($request, 'ai-session-handoff:'.$session, 10);

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
        // Table-qualified: the category aggregate below joins, which makes a
        // bare `is_active` ambiguous.
        $offers = Offer::query()
            ->where('offers.is_active', true)
            ->whereHas('supplier', fn ($s) => $s->where('suppliers.is_active', true));

        $total = $offers->clone()->count();

        // Aggregate in SQL — this endpoint is public and hit on every session.
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
            // Honest signal for the UI: below this the shortlist is inherently thin.
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
            return 'В каталоге пока нет активных офферов, поэтому подбирать не из чего. '
                .'Заведите поставщиков и товары в админке — после этого подбор начнёт работать.';
        }

        $scale = 'Сейчас в каталоге '.$this->plural($total, 'активный оффер', 'активных оффера', 'активных офферов')
            .' от '.$this->plural($suppliers, 'поставщика', 'поставщиков', 'поставщиков').'.';

        $thin = ($stats['is_thin'] ?? false)
            ? ' Каталог пока небольшой — если точного совпадения не будет, я покажу ближайшее и скажу, чего не хватает.'
            : '';

        return 'Опишите задачу по упаковке своими словами: тип, размеры в мм, объём, город. '
            ."\n\n".$scale.$thin;
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

    private function throttle(Request $request, string $key, int $max): void
    {
        $ip = $request->ip() ?? 'unknown';
        $bucket = 'ai:'.$key.':'.$ip;
        if (RateLimiter::tooManyAttempts($bucket, $max)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Too many AI requests, try later');
        }
        RateLimiter::hit($bucket, 60);
    }
}
