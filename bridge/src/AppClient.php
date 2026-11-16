<?php

namespace Bridge;

/** HTTPS calls to the app (outbound only; the app never connects into the network). */
final class AppClient
{
    public function __construct(private Config $config) {}

    public function sendMonth(array $payload): array
    {
        $body = gzencode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 6);

        return $this->request('POST', '/api/attendance-sync', $body, ['Content-Type: application/json', 'Content-Encoding: gzip']);
    }

    /** @return array{pending: bool, months: list<string>} */
    public function pending(): array
    {
        return $this->request('GET', '/api/attendance-sync/pending');
    }

    public function status(bool $ok, ?string $message = null): void
    {
        $this->request('POST', '/api/attendance-sync/status', json_encode(['ok' => $ok, 'message' => $message ? mb_substr($message, 0, 2000) : null]), ['Content-Type: application/json']);
    }

    private function request(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        if ($this->config->appUrl === '' || $this->config->token === '') {
            throw new \RuntimeException('APP_URL or SYNC_TOKEN is not set.');
        }
        $ch = curl_init($this->config->appUrl.$path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$this->config->token, 'Accept: application/json', ...$headers],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("$method $path failed: $error");
        }
        $json = json_decode((string) $response, true);
        if ($code >= 400 || ! is_array($json)) {
            throw new \RuntimeException("$method $path → HTTP $code: ".mb_substr((string) $response, 0, 300));
        }

        return $json;
    }
}
