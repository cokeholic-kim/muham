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
                'error' => 'Discord request failed.',
            ];
        }

        if (!preg_match('/^HTTP\/\S+\s+2\d\d\b/', (string)$statusLine)) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Discord webhook returned an error.',
            ];
        }

        return [
            'sent' => true,
            'skipped' => false,
            'error' => null,
        ];
    }
}
