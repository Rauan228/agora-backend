<?php

namespace App\Services\Ai;

/**
 * WaveSpeed / DeepSeek Flash pricing helper.
 * Admin-only visibility — never expose on public product API.
 */
class LlmCost
{
    /**
     * @return array{input_per_mtok: float, output_per_mtok: float, model: string, currency: string}
     */
    public static function rates(): array
    {
        return [
            'input_per_mtok' => (float) config('services.wavespeed.price_input_per_mtok', 0.17),
            'output_per_mtok' => (float) config('services.wavespeed.price_output_per_mtok', 0.34),
            'model' => (string) config('services.wavespeed.model', 'deepseek/deepseek-v4-flash'),
            'currency' => 'USD',
        ];
    }

    /**
     * @param  array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}|null  $usage
     * @return array{
     *   prompt_tokens: int,
     *   completion_tokens: int,
     *   total_tokens: int,
     *   cost_usd: float,
     *   estimated: bool,
     *   model: string,
     *   rates: array{input_per_mtok: float, output_per_mtok: float}
     * }
     */
    public static function fromUsage(?array $usage, ?string $model = null, bool $estimated = false): array
    {
        $rates = self::rates();
        $in = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);
        if ($in === 0 && $out === 0 && isset($usage['total_tokens'])) {
            // Unknown split — treat as all input (conservative-ish for billing display)
            $in = (int) $usage['total_tokens'];
        }
        $cost = ($in / 1_000_000) * $rates['input_per_mtok']
            + ($out / 1_000_000) * $rates['output_per_mtok'];

        return [
            'prompt_tokens' => $in,
            'completion_tokens' => $out,
            'total_tokens' => $in + $out,
            'cost_usd' => round($cost, 8),
            'estimated' => $estimated,
            'model' => $model ?: $rates['model'],
            'rates' => [
                'input_per_mtok' => $rates['input_per_mtok'],
                'output_per_mtok' => $rates['output_per_mtok'],
            ],
        ];
    }

    /**
     * Fallback when API does not return usage (common on some stream paths).
     * ~4 chars/token for mixed RU/EN is a rough underestimate of tokens → slightly high cost.
     */
    public static function estimateFromText(string $inputText, string $outputText, ?string $model = null): array
    {
        $in = max(1, (int) ceil(mb_strlen($inputText) / 3.5));
        $out = max(0, (int) ceil(mb_strlen($outputText) / 3.5));

        return self::fromUsage([
            'prompt_tokens' => $in,
            'completion_tokens' => $out,
        ], $model, estimated: true);
    }

    /**
     * @param  list<array<string, mixed>>  $parts  each from fromUsage()
     * @return array<string, mixed>
     */
    public static function mergeParts(array $parts): array
    {
        $in = 0;
        $out = 0;
        $cost = 0.0;
        $estimated = false;
        $model = self::rates()['model'];
        $calls = [];

        foreach ($parts as $p) {
            if ($p === [] || $p === null) {
                continue;
            }
            $in += (int) ($p['prompt_tokens'] ?? 0);
            $out += (int) ($p['completion_tokens'] ?? 0);
            $cost += (float) ($p['cost_usd'] ?? 0);
            $estimated = $estimated || ! empty($p['estimated']);
            if (! empty($p['model'])) {
                $model = (string) $p['model'];
            }
            if (! empty($p['label'])) {
                $calls[] = [
                    'label' => $p['label'],
                    'prompt_tokens' => (int) ($p['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($p['completion_tokens'] ?? 0),
                    'cost_usd' => (float) ($p['cost_usd'] ?? 0),
                    'estimated' => ! empty($p['estimated']),
                    'model' => $p['model'] ?? $model,
                ];
            }
        }

        $rates = self::rates();

        return [
            'prompt_tokens' => $in,
            'completion_tokens' => $out,
            'total_tokens' => $in + $out,
            'cost_usd' => round($cost, 8),
            'cost_rub_approx' => round($cost * (float) config('services.wavespeed.usd_to_rub', 90), 4),
            'estimated' => $estimated,
            'model' => $model,
            'llm_calls' => count($calls),
            'calls' => $calls,
            'rates' => [
                'input_per_mtok' => $rates['input_per_mtok'],
                'output_per_mtok' => $rates['output_per_mtok'],
                'usd_to_rub' => (float) config('services.wavespeed.usd_to_rub', 90),
            ],
            'match_search_usd' => 0.0, // deterministic SQL — free
        ];
    }

    /**
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     * @return array<string, mixed>
     */
    public static function addSessionTotals(?array $a, ?array $b): array
    {
        $a = $a ?? self::emptySession();
        $b = $b ?? self::emptySession();

        return [
            'prompt_tokens' => (int) ($a['prompt_tokens'] ?? 0) + (int) ($b['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($a['completion_tokens'] ?? 0) + (int) ($b['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($a['total_tokens'] ?? 0) + (int) ($b['total_tokens'] ?? 0),
            'cost_usd' => round((float) ($a['cost_usd'] ?? 0) + (float) ($b['cost_usd'] ?? 0), 8),
            'cost_rub_approx' => round(
                ((float) ($a['cost_usd'] ?? 0) + (float) ($b['cost_usd'] ?? 0))
                * (float) config('services.wavespeed.usd_to_rub', 90),
                4
            ),
            'llm_calls' => (int) ($a['llm_calls'] ?? 0) + (int) ($b['llm_calls'] ?? 0),
            'messages_with_llm' => (int) ($a['messages_with_llm'] ?? 0) + (int) ($b['messages_with_llm'] ?? 0),
            'user_messages' => (int) ($a['user_messages'] ?? 0) + (int) ($b['user_messages'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptySession(): array
    {
        return [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => 0.0,
            'cost_rub_approx' => 0.0,
            'llm_calls' => 0,
            'messages_with_llm' => 0,
            'user_messages' => 0,
        ];
    }
}
