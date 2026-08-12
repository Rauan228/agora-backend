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
     * @return array{content: string, model: string, usage?: array, raw?: array}|null
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

            $usage = data_get($data, 'usage');
            $out = [
                'content' => $content,
                'model' => $model,
                'raw' => $data,
            ];
            if (is_array($usage)) {
                $out['usage'] = [
                    'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('WaveSpeed exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Streams a completion, invoking $onDelta for every text chunk.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string): void  $onDelta
     * @return array{content: string, model: string, usage?: array}|null
     */
    public function streamChat(array $messages, callable $onDelta, float $temperature = 0.3): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $base = rtrim((string) config('services.wavespeed.base_url', 'https://llm.wavespeed.ai/v1'), '/');
        $model = (string) config('services.wavespeed.model', 'deepseek/deepseek-v4-flash');
        $key = (string) config('services.wavespeed.key');

        $full = '';
        $buffer = '';
        $usage = null;

        try {
            $response = Http::withToken($key)
                ->timeout((int) config('services.wavespeed.timeout', 45))
                ->withOptions([
                    'stream' => true,
                    'headers' => ['Accept' => 'text/event-stream'],
                ])
                ->post($base.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'stream' => true,
                    // OpenAI-compatible: last SSE frame may include usage
                    'stream_options' => ['include_usage' => true],
                ]);

            if (! $response->successful()) {
                Log::warning('WaveSpeed stream HTTP error', ['status' => $response->status()]);

                return null;
            }

            $body = $response->toPsrResponse()->getBody();

            while (! $body->eof()) {
                $chunk = $body->read(1024);
                if ($chunk === '') {
                    continue;
                }
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);

                    if ($line === '' || ! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }

                    $json = json_decode($data, true);
                    if (! is_array($json)) {
                        continue;
                    }
                    $delta = data_get($json, 'choices.0.delta.content');
                    if (is_string($delta) && $delta !== '') {
                        $full .= $delta;
                        $onDelta($delta);
                    }
                    $u = data_get($json, 'usage');
                    if (is_array($u)) {
                        $usage = [
                            'prompt_tokens' => (int) ($u['prompt_tokens'] ?? 0),
                            'completion_tokens' => (int) ($u['completion_tokens'] ?? 0),
                            'total_tokens' => (int) ($u['total_tokens'] ?? 0),
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('WaveSpeed stream exception: '.$e->getMessage());

            return $full !== '' ? [
                'content' => $full,
                'model' => $model,
                'usage' => $usage,
            ] : null;
        }

        if ($full === '') {
            return null;
        }

        $out = ['content' => $full, 'model' => $model];
        if (is_array($usage)) {
            $out['usage'] = $usage;
        }

        return $out;
    }
}
