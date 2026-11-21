<?php
declare(strict_types=1);

namespace WhatsUP\Services;

use RuntimeException;

/** HTTPS JSON webhook with deterministic request signing and idempotency header. */
final class WebhookClient implements DeliverySender
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $secret,
        private readonly int $timeoutSeconds = 15,
        private readonly bool $allowHttpLocal = false,
    ) {
        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        if (strtolower((string)$scheme) !== 'https') {
            $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
            if (!$this->allowHttpLocal || !in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Webhook endpoint must use HTTPS.');
        }
        if ($secret === '') throw new RuntimeException('Webhook signing secret is required.');
    }

    /** @return array<string,mixed> */
    public function send(array $payload, string $idempotencyKey): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string)time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $this->secret);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Webhook-Timestamp: ' . $timestamp,
            'X-Webhook-Signature: sha256=' . $signature,
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $responseBody = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($responseBody === false) throw new RuntimeException('Webhook transport failed: ' . $error);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
                'timeout' => $this->timeoutSeconds, 'ignore_errors' => true, 'follow_location' => 0,
            ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
            $responseBody = @file_get_contents($this->endpoint, false, $context);
            $status = 0;
            foreach ($http_response_header ?? [] as $line) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int)$m[1];
            if ($responseBody === false) throw new RuntimeException('Webhook transport failed.');
        }
        if ($status < 200 || $status >= 300) {
            $message = 'Webhook returned HTTP ' . $status . '.';
            if ($status >= 400 && $status < 500) throw new PermanentDeliveryException($message);
            throw new RuntimeException($message);
        }
        $decoded = json_decode((string)$responseBody, true);
        if (!is_array($decoded)) return ['status_code' => $status];
        $decoded['status_code'] = $status;
        return $decoded;
    }
}
