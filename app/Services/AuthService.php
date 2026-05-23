<?php
declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use InvalidArgumentException;
use RuntimeException;

final class AuthService
{
    /**
     * @return array<string, mixed>
     */
    public function signup(string $email, string $password, string $name): array
    {
        $email = $this->normalizeEmail($email);
        $name = trim($name);

        $this->assertPassword($password, $email);

        if ($name === '' || strlen($name) > 100) {
            throw new InvalidArgumentException('이름은 1자 이상 100자 이하로 입력해야 합니다.');
        }

        if ($this->findByEmail($email) !== null) {
            throw new RuntimeException('이미 가입된 이메일입니다.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        Database::statement(
            'INSERT INTO users (email, password_hash, name, role) VALUES (:email, :password_hash, :name, :role)',
            [
                'email' => $email,
                'password_hash' => $passwordHash,
                'name' => $name,
                'role' => 'user',
            ]
        );

        $user = $this->findByEmail($email);

        if ($user === null) {
            throw new RuntimeException('회원가입한 사용자를 찾을 수 없습니다.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function login(string $email, string $password): array
    {
        $user = $this->findByEmail($this->normalizeEmail($email), true);

        if ($user === null || !password_verify($password, (string)$user['password_hash'])) {
            throw new RuntimeException('이메일 또는 비밀번호가 올바르지 않습니다.');
        }

        unset($user['password_hash']);

        return $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $user = Database::fetchOne(
            'SELECT id, email, name, role, telegram_chat_id, ai_enabled, ai_daily_limit, created_at, updated_at FROM users WHERE id = :id',
            ['id' => $id]
        );

        return $this->normalizeUser($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByEmail(string $email, bool $includePasswordHash = false): ?array
    {
        $columns = $includePasswordHash
            ? 'id, email, password_hash, name, role, telegram_chat_id, ai_enabled, ai_daily_limit, created_at, updated_at'
            : 'id, email, name, role, telegram_chat_id, ai_enabled, ai_daily_limit, created_at, updated_at';

        $user = Database::fetchOne(
            sprintf('SELECT %s FROM users WHERE email = :email', $columns),
            ['email' => $email]
        );

        return $this->normalizeUser($user);
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new InvalidArgumentException('유효한 이메일을 입력해야 합니다.');
        }

        return $email;
    }

    private function assertPassword(string $password, string $email): void
    {
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('비밀번호는 10자 이상이어야 합니다.');
        }

        if (strtolower($password) === strtolower($email)) {
            throw new InvalidArgumentException('이메일과 같은 비밀번호는 사용할 수 없습니다.');
        }

        $localPart = strtolower(strstr($email, '@', true) ?: '');

        if ($localPart !== '' && str_contains(strtolower($password), $localPart)) {
            throw new InvalidArgumentException('이메일 계정명을 포함한 비밀번호는 사용할 수 없습니다.');
        }

        $commonPasswords = [
            'password',
            'password1',
            'password123',
            'qwerty123',
            'admin12345',
            'letmein123',
            '1234567890',
            '1111111111',
        ];

        if (in_array(strtolower($password), $commonPasswords, true)) {
            throw new InvalidArgumentException('너무 흔한 비밀번호는 사용할 수 없습니다.');
        }
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>|null
     */
    private function normalizeUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $user['id'] = (int)$user['id'];
        $user['ai_enabled'] = (int)($user['ai_enabled'] ?? 0);
        $user['ai_daily_limit'] = (int)($user['ai_daily_limit'] ?? 0);

        return $user;
    }
}
