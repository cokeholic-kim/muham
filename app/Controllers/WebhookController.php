<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\WebhookService;

final class WebhookController
{
    public function __construct(private readonly WebhookService $webhookService)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function workSummary(array $payload): array
    {
        $result = $this->webhookService->handleWorkSummary(
            $payload,
            $this->sourceIp(),
            $this->secret()
        );

        $status = match (true) {
            ($result['message'] ?? '') === '이미 처리된 requestId입니다.' => 409,
            $result['result'] === 'rejected' => 403,
            $result['result'] === 'failed' => 502,
            default => 200,
        };

        return [
            'status' => $status,
            'body' => $result,
        ];
    }

    private function sourceIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private function secret(): ?string
    {
        $header = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? null;

        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (is_string($authorization) && str_starts_with($authorization, 'Bearer ')) {
            return trim(substr($authorization, 7));
        }

        return null;
    }
}
