<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;

final class TelegramService
{
    /**
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    public function sendMessage(string $message, ?string $chatId = null, ?string $botToken = null): array
    {
        $token = $botToken !== null && trim($botToken) !== ''
            ? trim($botToken)
            : Env::get('TELEGRAM_BOT_TOKEN');
        $chatId = $chatId !== null && trim($chatId) !== ''
            ? trim($chatId)
            : Env::get('TELEGRAM_DEFAULT_CHAT_ID');

        if ($token === '' || $chatId === '') {
            return [
                'sent' => false,
                'skipped' => true,
                'error' => 'Telegram configuration is missing.',
            ];
        }

        $endpoint = sprintf('https://api.telegram.org/bot%s/sendMessage', $token);
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $message,
            'disable_web_page_preview' => 'true',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);

        if ($response === false) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Telegram request failed.',
            ];
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            return [
                'sent' => false,
                'skipped' => false,
                'error' => 'Telegram API returned an error.',
            ];
        }

        return [
            'sent' => true,
            'skipped' => false,
            'error' => null,
        ];
    }
}
