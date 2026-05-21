<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use RuntimeException;

final class WebhookRequestLogService
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function record(
        string $path,
        string $method,
        string $sourceIp,
        string $rawBody,
        ?array $payload,
        string $parseStatus,
        ?string $errorMessage
    ): void {
        $headersJson = json_encode($this->safeHeaders(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadJson = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($headersJson === false || $payloadJson === false) {
            throw new RuntimeException('웹훅 요청 로그 JSON 인코딩에 실패했습니다.');
        }

        Database::statement(
            'INSERT INTO webhook_request_logs (
                request_id,
                path,
                method,
                source_ip,
                headers_json,
                body_sha256,
                raw_body,
                payload_json,
                parse_status,
                error_message
            ) VALUES (
                :request_id,
                :path,
                :method,
                :source_ip,
                :headers_json,
                :body_sha256,
                :raw_body,
                :payload_json,
                :parse_status,
                :error_message
            )',
            [
                'request_id' => $this->requestId($payload),
                'path' => $path,
                'method' => $method,
                'source_ip' => $sourceIp,
                'headers_json' => $headersJson,
                'body_sha256' => hash('sha256', $rawBody),
                'raw_body' => $rawBody === '' ? null : substr($rawBody, 0, 65535),
                'payload_json' => $payloadJson,
                'parse_status' => $parseStatus,
                'error_message' => $errorMessage,
            ]
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function requestId(?array $payload): ?string
    {
        $requestId = $payload['requestId'] ?? null;

        if (!is_string($requestId) || trim($requestId) === '') {
            return null;
        }

        return substr(trim($requestId), 0, 120);
    }

    /**
     * @return array<string, string>
     */
    private function safeHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (!str_starts_with($key, 'HTTP_') && !in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                continue;
            }

            if (in_array($key, ['HTTP_AUTHORIZATION', 'HTTP_X_WEBHOOK_SECRET', 'HTTP_COOKIE'], true)) {
                continue;
            }

            $headers[$key] = substr($value, 0, 512);
        }

        ksort($headers);

        return $headers;
    }
}
