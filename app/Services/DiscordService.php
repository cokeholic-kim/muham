<?php
declare(strict_types=1);

namespace App\Services;

final class DiscordService
{
    /**
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    public function sendMessage(string $webhookUrl, string $message): array
    {
        $webhookUrl = trim($webhookUrl);

        if ($webhookUrl === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'error' => 'Discord webhook URL is missing.',
            ];
        }

        $payload = json_encode(['content' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord payload encoding failed.',
            ];
        }

        if (function_exists('curl_init')) {
            return $this->sendWithCurl($webhookUrl, $payload);
        }

        return $this->sendWithStream($webhookUrl, $payload);
    }

    /**
     * @param array{path: string, name: string, mime: string}|null $file
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    public function sendMessageWithFile(string $webhookUrl, string $message, ?array $file): array
    {
        if ($file === null) {
            return $this->sendMessage($webhookUrl, $message);
        }

        $webhookUrl = trim($webhookUrl);

        if ($webhookUrl === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'error' => 'Discord webhook URL is missing.',
            ];
        }

        if (!function_exists('curl_init') || !class_exists(\CURLFile::class)) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord file upload requires the PHP cURL extension.',
            ];
        }

        if (!is_file($file['path']) || !is_readable($file['path'])) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord upload file is not readable.',
            ];
        }

        $payload = json_encode(['content' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord payload encoding failed.',
            ];
        }

        $curl = curl_init($webhookUrl);

        if ($curl === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord cURL initialization failed.',
            ];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'payload_json' => $payload,
                'files[0]' => new \CURLFile($file['path'], $file['mime'], $file['name']),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord cURL request failed: ' . ($curlError !== '' ? $curlError : 'unknown error'),
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => sprintf('Discord webhook returned HTTP %d: %s', $statusCode, $this->responseSnippet((string)$response)),
            ];
        }

        return [
            'sent' => true,
            'skipped' => false,
            'error' => null,
        ];
    }

    /**
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    private function sendWithCurl(string $webhookUrl, string $payload): array
    {
        $curl = curl_init($webhookUrl);

        if ($curl === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord cURL initialization failed.',
            ];
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord cURL request failed: ' . ($curlError !== '' ? $curlError : 'unknown error'),
            ];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => sprintf('Discord webhook returned HTTP %d: %s', $statusCode, $this->responseSnippet((string)$response)),
            ];
        }

        return [
            'sent' => true,
            'skipped' => false,
            'error' => null,
        ];
    }

    /**
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    private function sendWithStream(string $webhookUrl, string $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents($webhookUrl, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($response === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord stream request failed. allow_url_fopen may be disabled.',
            ];
        }

        if (!preg_match('/^HTTP\/\S+\s+2\d\d\b/', (string)$statusLine)) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord webhook returned an error: ' . (string)$statusLine . ' ' . $this->responseSnippet((string)$response),
            ];
        }

        return [
            'sent' => true,
            'skipped' => false,
            'error' => null,
        ];
    }

    private function responseSnippet(string $response): string
    {
        $response = trim($response);

        if ($response === '') {
            return 'empty response';
        }

        return mb_substr($response, 0, 300);
    }
}
