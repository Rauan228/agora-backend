<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSession;
use App\Services\Ai\AiMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Public AI matching for product frontend (catalog-grounded).
 */
class AiSessionController extends Controller
{
    public function __construct(
        private AiMatchingService $ai,
    ) {}

    public function store(Request $request)
    {
        $this->throttle($request, 'ai-session-create', 30);

        $session = $this->ai->createSession(
            $request->string('client_key')->toString() ?: null
        );

        return response()->json([
            'session_id' => $session->id,
            'status' => $session->status,
            'welcome' => 'Я подберу упаковку из каталога Agora. Опишите задачу: тип, размеры (мм), объём, город. Например: «самосбор 400×300×200, бурый, 5000 шт, Москва».',
            'suggested_replies' => [
                'Гофрокороба для e-com, Москва',
                'Самосбор 400×300×200 мм',
                'Гофролист Т-23 оптом',
                'Нужен скотч и стрейч вместе с коробами',
            ],
        ], 201);
    }

    public function show(string $session)
    {
        $model = AiSession::with('messages')->findOrFail($session);

        return response()->json([
            'session_id' => $model->id,
            'status' => $model->status,
            'structured_query' => $model->structured_query,
            'last_match_ids' => $model->last_match_ids,
            'messages' => $model->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'meta' => $m->meta,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function message(Request $request, string $session)
    {
        $this->throttle($request, 'ai-session-msg:'.$session, 40);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $model = AiSession::findOrFail($session);
        if ($model->status === 'closed') {
            throw ValidationException::withMessages(['session' => 'Session closed']);
        }

        $result = $this->ai->handleMessage($model, $data['message']);

        return response()->json($result);
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

    private function throttle(Request $request, string $key, int $max): void
    {
        $ip = $request->ip() ?? 'unknown';
        $bucket = 'ai:'.$key.':'.$ip;
        if (RateLimiter::tooManyAttempts($bucket, $max)) {
            abort(429, 'Too many AI requests, try later');
        }
        RateLimiter::hit($bucket, 60);
    }
}
