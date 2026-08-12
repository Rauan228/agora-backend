<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI-compatible client for WaveSpeed LLM API.
 * @see https://llm.wavespeed.ai/v1
 */
class WaveSpeedClient
{
    public function enabled(): bool
    {
        return (bool) config('services.wavespeed.key')
            && filter_var(config('services.wavespeed.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{content: string, model: string, raw?: array}|null
     */
    public function chat(array $messages, bool $json = true, float $temperature = 0.2): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $base = rtrim((string) config('services.wavespeed.base_url', 'https://llm.wavespeed.ai/v1'), '/');
        $model = (string) config('services.wavespeed.model', 'deepseek/deepseek-v4-flash');
        $key = (string) config('services.wavespeed.key');

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.wavespeed.timeout', 45))
                ->acceptJson()
                ->post($base.'/chat/completions', $payload);

            if (! $response->successful()) {
                Log::warning('WaveSpeed HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            $data = $response->json();
            $content = data_get($data, 'choices.0.message.content');
            if (! is_string($content) || $content === '') {
                return null;
            }

            return [
                'content' => $content,
                'model' => $model,
                'raw' => $data,
            ];
        } catch (\Throwable $e) {
            Log::warning('WaveSpeed exception: '.$e->getMessage());

            return null;
        }
    }
}
