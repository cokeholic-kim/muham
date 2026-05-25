<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class SupportInquiryService
{
    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const IMAGE_MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly DiscordService $discordService,
        private readonly AppSettingService $appSettingService
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(array $user, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = Database::connection()->prepare(
            sprintf(
                'SELECT
                    id,
                    subject,
                    message,
                    image_filename,
                    image_mime,
                    image_size,
                    discord_sent,
                    discord_error,
                    status,
                    admin_reply,
                    answered_by,
                    answered_at,
                    user_read_at,
                    closed_by,
                    closed_at,
                    created_at,
                    updated_at
                FROM support_inquiries
                WHERE user_id = :user_id
                ORDER BY created_at DESC, id DESC
                LIMIT %d',
                $limit
            )
        );
        $statement->execute(['user_id' => (int)$user['id']]);

        return array_map(fn (array $row): array => $this->normalizeInquiry($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(string $status = 'all', int $limit = 100): array
    {
        $status = $this->statusFilter($status);
        $limit = max(1, min(200, $limit));
        $where = $status === 'all' ? '' : 'WHERE i.status = :status';
        $statement = Database::connection()->prepare(
            sprintf(
                'SELECT
                    i.id,
                    i.user_id,
                    u.email,
                    u.name,
                    i.subject,
                    i.message,
                    i.image_filename,
                    i.image_mime,
                    i.image_size,
                    i.discord_sent,
                    i.discord_error,
                    i.status,
                    i.admin_reply,
                    i.answered_by,
                    i.answered_at,
                    i.user_read_at,
                    i.closed_by,
                    i.closed_at,
                    i.created_at,
                    i.updated_at
                FROM support_inquiries i
                INNER JOIN users u ON u.id = i.user_id
                %s
                ORDER BY FIELD(i.status, "open", "answered", "closed"), i.created_at DESC, i.id DESC
                LIMIT %d',
                $where,
                $limit
            )
        );
        $parameters = $status === 'all' ? [] : ['status' => $status];
        $statement->execute($parameters);

        return array_map(fn (array $row): array => $this->normalizeInquiry($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{all: int, open: int, answered: int, closed: int}
     */
    public function adminStatusCounts(): array
    {
        $rows = Database::fetchAll(
            'SELECT status, COUNT(*) AS count
            FROM support_inquiries
            GROUP BY status'
        );
        $counts = [
            'all' => 0,
            'open' => 0,
            'answered' => 0,
            'closed' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            $count = (int)($row['count'] ?? 0);

            if (isset($counts[$status])) {
                $counts[$status] = $count;
                $counts['all'] += $count;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function unreadAnswerCount(array $user): int
    {
        $row = Database::fetchOne(
            'SELECT COUNT(*) AS count
            FROM support_inquiries
            WHERE user_id = :user_id
              AND status = :status
              AND admin_reply IS NOT NULL
              AND user_read_at IS NULL',
            [
                'user_id' => (int)$user['id'],
                'status' => 'answered',
            ]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function markAnsweredAsRead(array $user): int
    {
        $statement = Database::statement(
            'UPDATE support_inquiries
            SET user_read_at = NOW()
            WHERE user_id = :user_id
              AND status = :status
              AND admin_reply IS NOT NULL
              AND user_read_at IS NULL',
            [
                'user_id' => (int)$user['id'],
                'status' => 'answered',
            ]
        );

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function messagesForUser(array $user, int $inquiryId): array
    {
        $inquiry = Database::fetchOne(
            'SELECT id, user_id, message, created_at
            FROM support_inquiries
            WHERE id = :id
              AND user_id = :user_id
            LIMIT 1',
            [
                'id' => $inquiryId,
                'user_id' => (int)$user['id'],
            ]
        );

        if (!is_array($inquiry)) {
            throw new RuntimeException('문의를 찾을 수 없습니다.');
        }

        return $this->conversationMessages($inquiry);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messagesForAdmin(int $inquiryId): array
    {
        $inquiry = Database::fetchOne(
            'SELECT id, user_id, message, created_at
            FROM support_inquiries
            WHERE id = :id
            LIMIT 1',
            ['id' => $inquiryId]
        );

        if (!is_array($inquiry)) {
            throw new RuntimeException('문의를 찾을 수 없습니다.');
        }

        return $this->conversationMessages($inquiry);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $files
     * @return array{inquiryId: int, discordSent: bool, discordError: string|null}
     */
    public function submit(array $user, array $payload, array $files): array
    {
        $subject = $this->subject($payload['subject'] ?? null);
        $message = $this->message($payload['message'] ?? null);
        $userId = (int)$user['id'];
        $this->enforceRateLimit($user);
        $image = $this->imageUpload($files['image'] ?? null);
        $inquiryId = $this->insertInquiry($userId, $subject, $message, $image);
        $discordResult = $this->sendToDiscord($inquiryId, $user, $subject, $message, $image);

        Database::statement(
            'UPDATE support_inquiries
            SET discord_sent = :discord_sent,
                discord_error = :discord_error
            WHERE id = :id',
            [
                'id' => $inquiryId,
                'discord_sent' => $discordResult['sent'] ? 1 : 0,
                'discord_error' => $discordResult['error'],
            ]
        );

        return [
            'inquiryId' => $inquiryId,
            'discordSent' => $discordResult['sent'],
            'discordError' => $discordResult['error'],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function addUserMessage(array $user, int $inquiryId, array $payload): array
    {
        $message = $this->message($payload['message'] ?? null);
        $userId = (int)$user['id'];

        return Database::transaction(function (PDO $pdo) use ($inquiryId, $message, $userId): array {
            $before = $this->findForUpdate($pdo, $inquiryId);

            if ((int)$before['user_id'] !== $userId) {
                throw new RuntimeException('문의를 찾을 수 없습니다.');
            }

            $this->assertInquiryIsOpen((string)$before['status']);
            $this->insertMessage($pdo, $inquiryId, $userId, 'user', $message);

            $statement = $pdo->prepare(
                'UPDATE support_inquiries
                SET status = :status,
                    user_read_at = NULL,
                    updated_at = NOW()
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $inquiryId,
                'status' => 'open',
            ]);

            return [
                'before' => $this->normalizeInquiry($before),
                'after' => $this->normalizeInquiry($this->findForUpdate($pdo, $inquiryId)),
            ];
        });
    }

    /**
     * @param array<string, mixed> $admin
     * @param array<string, mixed> $payload
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function answer(array $admin, int $inquiryId, array $payload): array
    {
        $reply = $this->adminReply($payload['adminReply'] ?? null);
        $adminId = (int)$admin['id'];

        return Database::transaction(function (PDO $pdo) use ($inquiryId, $reply, $adminId): array {
            $before = $this->findForUpdate($pdo, $inquiryId);
            $this->assertInquiryIsOpen((string)$before['status']);
            $this->insertMessage($pdo, $inquiryId, $adminId, 'admin', $reply);

            $statement = $pdo->prepare(
                'UPDATE support_inquiries
                SET status = :status,
                    admin_reply = :admin_reply,
                    answered_by = :answered_by,
                    answered_at = NOW(),
                    user_read_at = NULL,
                    closed_by = NULL,
                    closed_at = NULL
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $inquiryId,
                'status' => 'answered',
                'admin_reply' => $reply,
                'answered_by' => $adminId,
            ]);

            return [
                'before' => $this->normalizeInquiry($before),
                'after' => $this->normalizeInquiry($this->findForUpdate($pdo, $inquiryId)),
            ];
        });
    }

    /**
     * @param array<string, mixed> $admin
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function close(array $admin, int $inquiryId): array
    {
        $adminId = (int)$admin['id'];

        return Database::transaction(function (PDO $pdo) use ($inquiryId, $adminId): array {
            $before = $this->findForUpdate($pdo, $inquiryId);
            $statement = $pdo->prepare(
                'UPDATE support_inquiries
                SET status = :status,
                    closed_by = :closed_by,
                    closed_at = NOW()
                WHERE id = :id'
            );
            $statement->execute([
                'id' => $inquiryId,
                'status' => 'closed',
                'closed_by' => $adminId,
            ]);

            return [
                'before' => $this->normalizeInquiry($before),
                'after' => $this->normalizeInquiry($this->findForUpdate($pdo, $inquiryId)),
            ];
        });
    }

    private function subject(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('문의 제목을 입력해야 합니다.');
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 120) {
            throw new InvalidArgumentException('문의 제목은 1자 이상 120자 이하로 입력해야 합니다.');
        }

        return $value;
    }

    private function message(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('문의 내용을 입력해야 합니다.');
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 5000) {
            throw new InvalidArgumentException('문의 내용은 1자 이상 5000자 이하로 입력해야 합니다.');
        }

        return $value;
    }

    private function adminReply(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('답변 내용을 입력해야 합니다.');
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 5000) {
            throw new InvalidArgumentException('답변 내용은 1자 이상 5000자 이하로 입력해야 합니다.');
        }

        return $value;
    }

    private function assertInquiryIsOpen(string $status): void
    {
        if ($status === 'closed') {
            throw new InvalidArgumentException('종료된 문의에는 추가 메시지를 남길 수 없습니다.');
        }
    }

    public function statusFilter(string $status): string
    {
        $status = trim($status);

        return in_array($status, ['all', 'open', 'answered', 'closed'], true) ? $status : 'all';
    }

    /**
     * @param array<string, mixed> $user
     */
    private function enforceRateLimit(array $user): void
    {
        if (($user['role'] ?? '') === 'admin') {
            return;
        }

        $userId = (int)$user['id'];
        $limits = $this->appSettingService->supportRateLimits();
        $hourLimit = $limits['maxPerHour'];
        $dayLimit = $limits['maxPerDay'];

        if ($hourLimit > 0) {
            $hourCount = $this->countSince($userId, '1 HOUR');

            if ($hourCount >= $hourLimit) {
                throw new InvalidArgumentException(sprintf('문의는 1시간에 최대 %d건까지 보낼 수 있습니다.', $hourLimit));
            }
        }

        if ($dayLimit > 0) {
            $dayCount = $this->countSince($userId, '1 DAY');

            if ($dayCount >= $dayLimit) {
                throw new InvalidArgumentException(sprintf('문의는 하루에 최대 %d건까지 보낼 수 있습니다.', $dayLimit));
            }
        }
    }

    private function countSince(int $userId, string $interval): int
    {
        if (!in_array($interval, ['1 HOUR', '1 DAY'], true)) {
            throw new RuntimeException('지원 문의 제한 기간이 올바르지 않습니다.');
        }

        $row = Database::fetchOne(
            sprintf(
                'SELECT COUNT(*) AS count
                FROM support_inquiries
                WHERE user_id = :user_id
                  AND created_at >= DATE_SUB(NOW(), INTERVAL %s)',
                $interval
            ),
            ['user_id' => $userId]
        );

        return (int)($row['count'] ?? 0);
    }

    /**
     * @param mixed $upload
     * @return array{name: string, path: string, mime: string, size: int}|null
     */
    private function imageUpload(mixed $upload): ?array
    {
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('이미지 업로드에 실패했습니다.');
        }

        $path = isset($upload['tmp_name']) ? (string)$upload['tmp_name'] : '';
        $size = isset($upload['size']) ? (int)$upload['size'] : 0;
        $originalName = isset($upload['name']) ? (string)$upload['name'] : 'screenshot';

        if ($path === '' || !is_uploaded_file($path)) {
            throw new InvalidArgumentException('업로드된 이미지 파일이 올바르지 않습니다.');
        }

        if ($size < 1 || $size > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('이미지는 8MB 이하로 업로드해야 합니다.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($path);

        if (!isset(self::IMAGE_MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('PNG, JPG, WEBP, GIF 이미지만 업로드할 수 있습니다.');
        }

        return [
            'name' => $this->safeFilename($originalName, self::IMAGE_MIME_EXTENSIONS[$mime]),
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    /**
     * @param array{name: string, path: string, mime: string, size: int}|null $image
     */
    private function insertInquiry(int $userId, string $subject, string $message, ?array $image): int
    {
        Database::statement(
            'INSERT INTO support_inquiries (
                user_id,
                subject,
                message,
                image_filename,
                image_mime,
                image_size
            ) VALUES (
                :user_id,
                :subject,
                :message,
                :image_filename,
                :image_mime,
                :image_size
            )',
            [
                'user_id' => $userId,
                'subject' => $subject,
                'message' => $message,
                'image_filename' => $image['name'] ?? null,
                'image_mime' => $image['mime'] ?? null,
                'image_size' => $image['size'] ?? null,
            ]
        );

        return (int)Database::connection()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $user
     * @param array{name: string, path: string, mime: string, size: int}|null $image
     * @return array{sent: bool, skipped: bool, error: string|null}
     */
    private function sendToDiscord(int $inquiryId, array $user, string $subject, string $message, ?array $image): array
    {
        $webhookUrl = trim(Env::get('SUPPORT_DISCORD_WEBHOOK_URL'));
        $content = sprintf(
            "**문의 #%d**\n사용자: %s <%s> (ID: %d)\n제목: %s\n이미지: %s\n\n%s",
            $inquiryId,
            $this->discordText((string)($user['name'] ?? '')),
            $this->discordText((string)($user['email'] ?? '')),
            (int)$user['id'],
            $this->discordText($subject),
            $image === null ? '없음' : $this->discordText($image['name'] . ' / ' . $this->formatBytes($image['size'])),
            $this->discordText($message)
        );

        return $this->discordService->sendMessageWithFile(
            $webhookUrl,
            mb_substr($content, 0, 1900),
            $image === null ? null : [
                'path' => $image['path'],
                'name' => $image['name'],
                'mime' => $image['mime'],
            ]
        );
    }

    private function insertMessage(PDO $pdo, int $inquiryId, int $senderUserId, string $senderRole, string $message): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO support_inquiry_messages (
                inquiry_id,
                sender_user_id,
                sender_role,
                message
            ) VALUES (
                :inquiry_id,
                :sender_user_id,
                :sender_role,
                :message
            )'
        );
        $statement->execute([
            'inquiry_id' => $inquiryId,
            'sender_user_id' => $senderUserId,
            'sender_role' => $senderRole,
            'message' => $message,
        ]);
    }

    /**
     * @param array<string, mixed> $inquiry
     * @return array<int, array<string, mixed>>
     */
    private function conversationMessages(array $inquiry): array
    {
        $messages = [[
            'id' => 0,
            'inquiry_id' => (int)$inquiry['id'],
            'sender_user_id' => (int)$inquiry['user_id'],
            'sender_role' => 'user',
            'message' => (string)$inquiry['message'],
            'created_at' => (string)$inquiry['created_at'],
            'is_initial' => true,
        ]];

        $statement = Database::connection()->prepare(
            'SELECT
                id,
                inquiry_id,
                sender_user_id,
                sender_role,
                message,
                created_at
            FROM support_inquiry_messages
            WHERE inquiry_id = :inquiry_id
            ORDER BY created_at ASC, id ASC'
        );
        $statement->execute(['inquiry_id' => (int)$inquiry['id']]);

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $message) {
            $messages[] = [
                'id' => (int)$message['id'],
                'inquiry_id' => (int)$message['inquiry_id'],
                'sender_user_id' => (int)$message['sender_user_id'],
                'sender_role' => (string)$message['sender_role'],
                'message' => (string)$message['message'],
                'created_at' => (string)$message['created_at'],
                'is_initial' => false,
            ];
        }

        return $messages;
    }

    private function safeFilename(string $name, string $extension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base);
        $base = trim((string)$base, '.-');

        if ($base === '') {
            $base = 'support-image';
        }

        return substr($base, 0, 80) . '.' . $extension;
    }

    private function discordText(string $value): string
    {
        return str_replace(['@everyone', '@here'], ['@ everyone', '@ here'], $value);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1fMB', $bytes / 1024 / 1024);
        }

        return sprintf('%.1fKB', max(1, $bytes) / 1024);
    }

    /**
     * @return array<string, mixed>
     */
    private function findForUpdate(PDO $pdo, int $inquiryId): array
    {
        $statement = $pdo->prepare(
            'SELECT
                id,
                user_id,
                subject,
                message,
                image_filename,
                image_mime,
                image_size,
                discord_sent,
                discord_error,
                status,
                admin_reply,
                answered_by,
                answered_at,
                user_read_at,
                closed_by,
                closed_at,
                created_at,
                updated_at
            FROM support_inquiries
            WHERE id = :id
            LIMIT 1
            FOR UPDATE'
        );
        $statement->execute(['id' => $inquiryId]);
        $inquiry = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($inquiry)) {
            throw new RuntimeException('문의를 찾을 수 없습니다.');
        }

        return $inquiry;
    }

    /**
     * @param array<string, mixed> $inquiry
     * @return array<string, mixed>
     */
    private function normalizeInquiry(array $inquiry): array
    {
        $inquiry['id'] = (int)$inquiry['id'];
        $inquiry['user_id'] = isset($inquiry['user_id']) ? (int)$inquiry['user_id'] : null;
        $inquiry['image_size'] = isset($inquiry['image_size']) && $inquiry['image_size'] !== null
            ? (int)$inquiry['image_size']
            : null;
        $inquiry['discord_sent'] = (int)($inquiry['discord_sent'] ?? 0);
        $inquiry['answered_by'] = isset($inquiry['answered_by']) && $inquiry['answered_by'] !== null
            ? (int)$inquiry['answered_by']
            : null;
        $inquiry['closed_by'] = isset($inquiry['closed_by']) && $inquiry['closed_by'] !== null
            ? (int)$inquiry['closed_by']
            : null;

        return $inquiry;
    }
}
