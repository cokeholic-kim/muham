<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use App\Database\Database;
use InvalidArgumentException;
use RuntimeException;

final class AiCredentialService
{
    private const PROVIDERS = ['gemini', 'openai', 'anthropic'];

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function findForUser(array $user): ?array
    {
        $setting = Database::fetchOne(
            'SELECT
                id,
                user_id,
                provider,
                model,
                api_key_ciphertext,
                api_key_hint,
                created_at,
                updated_at
            FROM user_ai_settings
            WHERE user_id = :user_id
            LIMIT 1',
            ['user_id' => (int)$user['id']]
        );

        if ($setting === null) {
            return null;
        }

        $setting['id'] = (int)$setting['id'];
        $setting['user_id'] = (int)$setting['user_id'];

        return $setting;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveForUser(array $user, array $payload): array
    {
        $existing = $this->findForUser($user);
        $provider = $this->provider($payload['provider'] ?? ($existing['provider'] ?? 'gemini'));
        $model = $this->model($payload['model'] ?? null, $provider);
        $apiKey = isset($payload['apiKey']) && is_string($payload['apiKey'])
            ? trim($payload['apiKey'])
            : '';

        if ($apiKey === '' && $existing === null) {
            throw new InvalidArgumentException('AI API Key가 필요합니다.');
        }

        $ciphertext = $apiKey === ''
            ? (string)$existing['api_key_ciphertext']
            : $this->encrypt($apiKey);
        $hint = $apiKey === ''
            ? (string)$existing['api_key_hint']
            : $this->mask($apiKey);

        if ($existing === null) {
            Database::statement(
                'INSERT INTO user_ai_settings (
                    user_id,
                    provider,
                    model,
                    api_key_ciphertext,
                    api_key_hint
                ) VALUES (
                    :user_id,
                    :provider,
                    :model,
                    :api_key_ciphertext,
                    :api_key_hint
                )',
                [
                    'user_id' => (int)$user['id'],
                    'provider' => $provider,
                    'model' => $model,
                    'api_key_ciphertext' => $ciphertext,
                    'api_key_hint' => $hint,
                ]
            );
        } else {
            Database::statement(
                'UPDATE user_ai_settings
                SET provider = :provider,
                    model = :model,
                    api_key_ciphertext = :api_key_ciphertext,
                    api_key_hint = :api_key_hint
                WHERE user_id = :user_id',
                [
                    'user_id' => (int)$user['id'],
                    'provider' => $provider,
                    'model' => $model,
                    'api_key_ciphertext' => $ciphertext,
                    'api_key_hint' => $hint,
                ]
            );
        }

        return $this->findForUser($user) ?? throw new RuntimeException('저장된 AI 설정을 찾을 수 없습니다.');
    }

    /**
     * @param array<string, mixed> $user
     * @return array{provider: string, model: string, apiKey: string}
     */
    public function credentialsForUser(array $user): array
    {
        $setting = $this->findForUser($user);

        if ($setting === null) {
            throw new InvalidArgumentException('저장된 AI API Key가 없습니다.');
        }

        return [
            'provider' => (string)$setting['provider'],
            'model' => (string)$setting['model'],
            'apiKey' => $this->decrypt((string)$setting['api_key_ciphertext']),
        ];
    }

    public function defaultModel(string $provider): string
    {
        return match ($provider) {
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-sonnet-latest',
            default => 'gemini-2.0-flash',
        };
    }

    public function mask(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (strlen($value) <= 10) {
            return substr($value, 0, 2) . str_repeat('*', 6);
        }

        return substr($value, 0, 4) . str_repeat('*', 8) . substr($value, -4);
    }

    private function provider(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::PROVIDERS, true)) {
            throw new InvalidArgumentException('AI Provider가 올바르지 않습니다.');
        }

        return $value;
    }

    private function model(mixed $value, string $provider): string
    {
        if (!is_string($value) || trim($value) === '') {
            return $this->defaultModel($provider);
        }

        $value = trim($value);

        if (strlen($value) > 120) {
            throw new InvalidArgumentException('AI 모델명은 120자 이하여야 합니다.');
        }

        return $value;
    }

    private function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

        if ($cipherText === false) {
            throw new RuntimeException('AI API Key 암호화에 실패했습니다.');
        }

        return (string)json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'value' => base64_encode($cipherText),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function decrypt(string $payload): string
    {
        $data = json_decode($payload, true);

        if (!is_array($data) || !isset($data['iv'], $data['tag'], $data['value'])) {
            throw new RuntimeException('AI API Key 암호문 형식이 올바르지 않습니다.');
        }

        $plainText = openssl_decrypt(
            (string)base64_decode((string)$data['value'], true),
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            (string)base64_decode((string)$data['iv'], true),
            (string)base64_decode((string)$data['tag'], true)
        );

        if ($plainText === false) {
            throw new RuntimeException('AI API Key 복호화에 실패했습니다.');
        }

        return $plainText;
    }

    private function encryptionKey(): string
    {
        $material = Env::get('APP_KEY');

        if ($material === '') {
            $material = Env::required('SESSION_SECRET');
        }

        return hash('sha256', $material, true);
    }
}
