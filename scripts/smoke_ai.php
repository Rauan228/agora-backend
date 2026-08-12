<?php

$base = getenv('AGORA_API') ?: 'https://agora.178.88.115.213.sslip.io/api';

function req(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 60,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }

    return [$code, json_decode($raw, true), $raw];
}

[$code, $j, $raw] = req('POST', $base.'/ai/sessions', []);
echo "CREATE $code\n$raw\n";
$id = $j['session_id'] ?? null;
if (! $id) {
    exit(1);
}

[$code, $j, $raw] = req('POST', $base.'/ai/sessions/'.$id.'/messages', [
    'message' => 'гофрокороб 400x300x200 Москва 5000 шт бурый',
]);
echo "MESSAGE $code\n";
echo 'offers='.count($j['offers'] ?? [])." score0=".($j['offers'][0]['match_score'] ?? 'n/a')."\n";
echo 'slugs='.json_encode($j['structured_query']['category_slugs'] ?? [], JSON_UNESCAPED_UNICODE)."\n";
echo mb_substr($j['assistant_message'] ?? '', 0, 300)."\n";
