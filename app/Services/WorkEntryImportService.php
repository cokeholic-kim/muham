<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class WorkEntryImportService
{
    public function __construct(
        private readonly AiCredentialService $aiCredentialService
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array{source: string, entries: array<int, array<string, mixed>>, raw: array<mixed>|null}
     */
    public function preview(array $user, array $payload): array
    {
        $text = $this->text($payload['rawText'] ?? null);
        $year = $this->year($payload['baseYear'] ?? null);
        $mode = $this->mode($payload['parserMode'] ?? 'auto');
        $entries = $this->parseByPattern($text, $year);

        if ($entries !== [] && $mode !== 'ai') {
            return [
                'source' => 'pattern',
                'entries' => $entries,
                'raw' => null,
            ];
        }

        if ($mode === 'pattern') {
            throw new InvalidArgumentException('정규 포맷으로 해석할 수 있는 근무 시간이 없습니다.');
        }

        $credentials = $this->aiCredentialService->credentialsForUser($user);
        $raw = $this->parseByAi($text, $year, $credentials);

        return [
            'source' => 'ai',
            'entries' => $this->entriesFromAiResult($raw, $year),
            'raw' => $raw,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseByPattern(string $text, int $year): array
    {
        $entries = [];
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s*(\d{1,2})\s*(?:\/|,|월\s*)\s*(\d{1,2})(?:일)?\s+(.+)$/u', $line, $matches) !== 1) {
                continue;
            }

            $date = $this->dateFromParts($year, (int)$matches[1], (int)$matches[2]);
            $ranges = $this->timeRanges((string)$matches[3]);

            foreach ($ranges as [$start, $end]) {
                $entries[] = $this->entry($date, $start, $end, '일괄 입력');
            }
        }

        return $this->sortEntries($entries);
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private function timeRanges(string $value): array
    {
        preg_match_all('/(\d{1,2})(?::(\d{1,2}))?\s*(?:-|~|–|—)\s*(\d{1,2})(?::(\d{1,2}))?/u', $value, $matches, PREG_SET_ORDER);
        $ranges = [];

        foreach ($matches as $match) {
            $start = $this->timeFromParts((int)$match[1], isset($match[2]) && $match[2] !== '' ? (int)$match[2] : 0);
            $end = $this->timeFromParts((int)$match[3], isset($match[4]) && $match[4] !== '' ? (int)$match[4] : 0);
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    /**
     * @param array{provider: string, model: string, apiKey: string} $credentials
     * @return array<mixed>
     */
    private function parseByAi(string $text, int $year, array $credentials): array
    {
        $prompt = $this->prompt($text, $year);
        $provider = $credentials['provider'];
        $model = $credentials['model'];
        $apiKey = $credentials['apiKey'];

        $rawText = match ($provider) {
            'openai' => $this->callJsonApi(
                'https://api.openai.com/v1/chat/completions',
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ],
                [
                    'model' => $model,
                    'temperature' => 0.1,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
                fn (array $data): string => (string)($data['choices'][0]['message']['content'] ?? '')
            ),
            'anthropic' => $this->callJsonApi(
                'https://api.anthropic.com/v1/messages',
                [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: 2023-06-01',
                ],
                [
                    'model' => $model,
                    'max_tokens' => 4096,
                    'temperature' => 0.1,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
                fn (array $data): string => implode("\n", array_map(
                    fn (array $item): string => (string)($item['text'] ?? ''),
                    is_array($data['content'] ?? null) ? $data['content'] : []
                ))
            ),
            default => $this->callJsonApi(
                'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey),
                ['Content-Type: application/json'],
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => ['temperature' => 0.1],
                ],
                fn (array $data): string => (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '')
            ),
        };

        return $this->jsonFromText($rawText);
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $body
     * @param callable(array<string, mixed>): string $extractor
     */
    private function callJsonApi(string $url, array $headers, array $body, callable $extractor): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL 확장이 필요합니다.');
        }

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('AI 요청을 초기화할 수 없습니다.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_values($headers),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
        ]);

        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response) || $response === '') {
            throw new RuntimeException('AI 응답이 비어 있습니다. ' . $error);
        }

        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException('AI 호출에 실패했습니다. HTTP ' . $status);
        }

        $text = trim($extractor($data));

        if ($text === '') {
            throw new RuntimeException('AI가 변환 결과를 반환하지 않았습니다.');
        }

        return $text;
    }

    /**
     * @return array<mixed>
     */
    private function jsonFromText(string $text): array
    {
        $cleaned = trim(preg_replace('/^```json\s*|\s*```$/i', '', $text) ?? $text);

        if (preg_match('/(\[[\s\S]*\]|\{[\s\S]*\})/u', $cleaned, $matches) === 1) {
            $cleaned = trim($matches[1]);
        }

        $data = json_decode($cleaned, true);

        if (!is_array($data)) {
            throw new RuntimeException('AI 변환 결과가 JSON 형식이 아닙니다.');
        }

        return $data;
    }

    /**
     * @param array<mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function entriesFromAiResult(array $items, int $year): array
    {
        $entries = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $date = isset($item['date']) && is_string($item['date'])
                ? $this->dateValue($item['date'])
                : null;

            if ($date === null) {
                continue;
            }

            $sessions = is_array($item['sessions'] ?? null) ? $item['sessions'] : [];

            foreach ($sessions as $session) {
                if (!is_array($session)) {
                    continue;
                }

                $start = isset($session['start']) && is_string($session['start']) ? $this->timeValue($session['start']) : null;
                $end = isset($session['end']) && is_string($session['end']) ? $this->timeValue($session['end']) : null;

                if ($start === null || $end === null) {
                    continue;
                }

                $note = isset($session['note']) && is_string($session['note']) && trim($session['note']) !== ''
                    ? trim($session['note'])
                    : 'AI 일괄 입력';
                $entries[] = $this->entry($date, $start, $end, $note);
            }
        }

        if ($entries === []) {
            throw new InvalidArgumentException('AI 변환 결과에서 저장 가능한 근무 시간을 찾지 못했습니다.');
        }

        return $this->sortEntries($entries);
    }

    private function prompt(string $text, int $year): string
    {
        return 'Context: You parse Korean work logs into JSON for database import.' . "\n"
            . 'Base year: ' . $year . "\n"
            . 'Return strictly valid JSON only. No markdown.' . "\n"
            . 'Schema: [{"date":"YYYY-MM-DD","sessions":[{"start":"HH:MM","end":"HH:MM","note":"optional"}]}]' . "\n"
            . 'Rules: Use the base year when year is missing. Split multiple time ranges on the same date into separate sessions. Sort by date and start time.' . "\n"
            . 'Input:' . "\n\"\"\"\n" . $text . "\n\"\"\"";
    }

    private function entry(string $date, string $start, string $end, string $memo): array
    {
        return [
            'workDate' => $date,
            'startAt' => $date . ' ' . $start,
            'endAt' => $date . ' ' . $end,
            'breakMinutes' => 0,
            'memo' => $memo,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => strcmp((string)$a['startAt'], (string)$b['startAt']));

        return $entries;
    }

    private function dateFromParts(int $year, int $month, int $day): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', sprintf('%d-%d-%d', $year, $month, $day));

        if ($date === false || (int)$date->format('n') !== $month || (int)$date->format('j') !== $day) {
            throw new InvalidArgumentException('근무일 형식이 올바르지 않습니다.');
        }

        return $date->format('Y-m-d');
    }

    private function dateValue(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function timeFromParts(int $hour, int $minute): string
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('근무 시간 형식이 올바르지 않습니다.');
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function timeValue(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})(?::(\d{1,2}))?$/', trim($value), $matches) !== 1) {
            return null;
        }

        return $this->timeFromParts((int)$matches[1], isset($matches[2]) ? (int)$matches[2] : 0);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('변환할 근무시간 텍스트가 필요합니다.');
        }

        if (strlen($value) > 20000) {
            throw new InvalidArgumentException('근무시간 텍스트는 20000자 이하여야 합니다.');
        }

        return trim($value);
    }

    private function year(mixed $value): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int)$value;
        }

        if (!is_int($value) || $value < 2000 || $value > 2100) {
            throw new InvalidArgumentException('기준 연도가 올바르지 않습니다.');
        }

        return $value;
    }

    private function mode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['auto', 'pattern', 'ai'], true)) {
            throw new InvalidArgumentException('파서 모드가 올바르지 않습니다.');
        }

        return $value;
    }
}
