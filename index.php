<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Config/Env.php';
require_once __DIR__ . '/app/Services/CsrfService.php';
require_once __DIR__ . '/app/Services/SessionService.php';
require_once __DIR__ . '/app/Database/Database.php';
require_once __DIR__ . '/app/Database/HealthCheck.php';
require_once __DIR__ . '/app/Services/AdminAiUserService.php';
require_once __DIR__ . '/app/Services/AiUsageService.php';
require_once __DIR__ . '/app/Services/AppSettingService.php';
require_once __DIR__ . '/app/Services/AuditLogService.php';
require_once __DIR__ . '/app/Services/AuthService.php';
require_once __DIR__ . '/app/Services/DiscordService.php';
require_once __DIR__ . '/app/Services/LoginAttemptService.php';
require_once __DIR__ . '/app/Services/NotificationSettingService.php';
require_once __DIR__ . '/app/Services/SupportInquiryService.php';
require_once __DIR__ . '/app/Services/TelegramService.php';
require_once __DIR__ . '/app/Services/WebhookRequestLogService.php';
require_once __DIR__ . '/app/Services/WebhookService.php';
require_once __DIR__ . '/app/Services/WorkEntryService.php';
require_once __DIR__ . '/app/Services/WorkEntryImportService.php';
require_once __DIR__ . '/app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/app/Controllers/AuthController.php';
require_once __DIR__ . '/app/Controllers/WebhookController.php';
require_once __DIR__ . '/app/Controllers/WebController.php';

use App\Controllers\AuthController;
use App\Controllers\WebController;
use App\Controllers\WebhookController;
use App\Config\Env;
use App\Database\HealthCheck;
use App\Middleware\AuthMiddleware;
use App\Services\AdminAiUserService;
use App\Services\AppSettingService;
use App\Services\AuditLogService;
use App\Services\AiUsageService;
use App\Services\AuthService;
use App\Services\DiscordService;
use App\Services\LoginAttemptService;
use App\Services\NotificationSettingService;
use App\Services\SupportInquiryService;
use App\Services\TelegramService;
use App\Services\WebhookRequestLogService;
use App\Services\WebhookService;
use App\Services\WorkEntryService;
use App\Services\WorkEntryImportService;

Env::load(__DIR__ . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Seoul'));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$routeMethod = $method === 'HEAD' ? 'GET' : $method;

function jsonResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    securityHeaders();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function securityHeaders(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://generativelanguage.googleapis.com https://api.openai.com https://api.anthropic.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

    if (Env::get('APP_ENV') === 'production') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * @return array<string, mixed>
 */
function readJsonPayloadFromRaw(string $rawBody): array
{
    if (trim($rawBody) === '') {
        return [];
    }

    $payload = json_decode($rawBody, true);

    if (!is_array($payload)) {
        throw new \InvalidArgumentException('유효한 JSON 요청 본문이 필요합니다.');
    }

    return $payload;
}

function authRuntimeStatus(\RuntimeException $e): int
{
    $message = $e->getMessage();

    if ($message === '이미 가입된 이메일입니다.') {
        return 409;
    }

    if ($message === '같은 시간대의 근무 기록이 이미 있습니다.') {
        return 409;
    }

    if ($message === '이미 처리된 requestId입니다.') {
        return 409;
    }

    if (
        $message === '로그인 실패 횟수가 많아 10분 후 다시 시도해야 합니다.' ||
        $message === '현재 IP에서 로그인 실패가 많아 10분 후 다시 시도해야 합니다.'
    ) {
        return 429;
    }

    if (
        $message === '이메일 또는 비밀번호가 올바르지 않습니다.' ||
        $message === '로그인이 필요합니다.' ||
        $message === '세션 사용자를 찾을 수 없습니다.'
    ) {
        return 401;
    }

    if ($message === '접근 권한이 없습니다.') {
        return 403;
    }

    if (
        $message === '근무 기록을 찾을 수 없습니다.' ||
        $message === '수정된 근무 기록을 찾을 수 없습니다.' ||
        $message === '삭제된 근무 기록을 찾을 수 없습니다.' ||
        $message === '대상 사용자를 찾을 수 없습니다.'
    ) {
        return 404;
    }

    return 400;
}

/**
 * @return array<string, string|null>
 */
function requestContext(): array
{
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $requestId = is_string($requestId) ? trim($requestId) : '';

    if ($requestId === '') {
        $requestId = bin2hex(random_bytes(16));
    }

    return [
        'request_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
        'request_id' => substr($requestId, 0, 120),
    ];
}

if (str_starts_with($path, '/api/')) {
    $auditLogService = new AuditLogService();
    $requestContext = requestContext();
    $webhookController = new WebhookController(
        new WebhookService(
            $auditLogService,
            new TelegramService(),
            new DiscordService(),
            new NotificationSettingService(),
            $requestContext
        )
    );
    $webhookRequestLogService = new WebhookRequestLogService();

    try {
        if ($routeMethod === 'POST' && $path === '/api/webhooks/work-summary') {
            $rawBody = file_get_contents('php://input');
            $rawBody = is_string($rawBody) ? $rawBody : '';

            try {
                $payload = readJsonPayloadFromRaw($rawBody);
                $webhookRequestLogService->record(
                    $path,
                    $method,
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    $rawBody,
                    $payload,
                    trim($rawBody) === '' ? 'empty' : 'parsed',
                    null,
                    false
                );
            } catch (\InvalidArgumentException $e) {
                $webhookRequestLogService->record(
                    $path,
                    $method,
                    (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                    $rawBody,
                    null,
                    'invalid_json',
                    $e->getMessage(),
                    false
                );
                throw $e;
            }

            $result = $webhookController->workSummary($payload);
            jsonResponse($result['body'], $result['status']);
        }

        jsonResponse(['message' => 'Not Found'], 404);
    } catch (\InvalidArgumentException $e) {
        jsonResponse(['message' => $e->getMessage()], 422);
    } catch (\RuntimeException $e) {
        jsonResponse(['message' => $e->getMessage()], authRuntimeStatus($e));
    } catch (\Throwable $e) {
        $body = ['message' => '서버 오류가 발생했습니다.'];

        if (Env::get('APP_DEBUG') === 'true') {
            $body['debug'] = $e->getMessage();
        }

        jsonResponse($body, 500);
    }
}

if ($path === '/health.json') {
    if (Env::get('APP_ENV') === 'production' && SessionService::userId() === null) {
        jsonResponse(['message' => 'Unauthorized'], 401);
    }

    if (Env::get('APP_ENV') === 'production') {
        $checks = HealthCheck::run();
        jsonResponse(['status' => $checks['status']]);
    }

    jsonResponse(HealthCheck::run());
}

if ($path === '/favicon.ico') {
    $faviconPath = __DIR__ . '/pwa-icons/icon-192.png';

    if (!is_file($faviconPath) || !is_readable($faviconPath)) {
        http_response_code(204);
        exit;
    }

    http_response_code(200);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    readfile($faviconPath);
    exit;
}

$authService = new AuthService();
$auditLogService = new AuditLogService();
$loginAttemptService = new LoginAttemptService();
$authMiddleware = new AuthMiddleware($authService);
$requestContext = requestContext();
$aiUsageService = new AiUsageService();
$appSettingService = new AppSettingService();
$notificationSettingService = new NotificationSettingService();
$webhookService = new WebhookService(
    $auditLogService,
    new TelegramService(),
    new DiscordService(),
    $notificationSettingService,
    $requestContext
);
$webController = new WebController(
    new AuthController($authService, $authMiddleware, $auditLogService, $loginAttemptService, $requestContext),
    $authMiddleware,
    new WorkEntryService($auditLogService, $requestContext),
    new WorkEntryImportService($aiUsageService),
    new AdminAiUserService(),
    $appSettingService,
    $notificationSettingService,
    new SupportInquiryService(new DiscordService(), $appSettingService),
    $auditLogService,
    $webhookService
);

if ($path === '/' && $routeMethod === 'GET') {
    $webController->home();
}

if ($path === '/features' && $routeMethod === 'GET') {
    $webController->features();
}

if ($path === '/guide' && $routeMethod === 'GET') {
    $webController->guide();
}

if ($path === '/faq' && $routeMethod === 'GET') {
    $webController->faq();
}

if ($path === '/privacy' && $routeMethod === 'GET') {
    $webController->privacy();
}

if ($path === '/terms' && $routeMethod === 'GET') {
    $webController->terms();
}

if ($path === '/support' && $routeMethod === 'GET') {
    $webController->supportForm();
}

if ($path === '/support' && $method === 'POST') {
    $webController->submitSupportInquiry($_POST, $_FILES);
}

if ($path === '/robots.txt' && $routeMethod === 'GET') {
    $webController->robotsTxt();
}

if ($path === '/sitemap.xml' && $routeMethod === 'GET') {
    $webController->sitemapXml();
}

if ($path === '/health' && $routeMethod === 'GET') {
    $webController->health();
}

if ($path === '/index.html' && $routeMethod === 'GET') {
    $webController->aiParserPrototype();
}

if ($path === '/login' && $routeMethod === 'GET') {
    $webController->loginForm($_GET);
}

if ($path === '/login' && $method === 'POST') {
    $webController->login($_POST);
}

if ($path === '/signup' && $routeMethod === 'GET') {
    $webController->signupForm($_GET);
}

if ($path === '/signup' && $method === 'POST') {
    $webController->signup($_POST);
}

if ($path === '/logout' && $method === 'POST') {
    $webController->logout();
}

if ($path === '/admin/ai-users' && $routeMethod === 'GET') {
    $webController->adminAiUsers();
}

if (preg_match('#^/admin/ai-users/([1-9][0-9]*)$#', $path, $matches) === 1 && $method === 'POST') {
    $webController->updateAdminAiUser((int)$matches[1], $_POST);
}

if ($path === '/admin/support-inquiries' && $routeMethod === 'GET') {
    $webController->adminSupportInquiries($_GET);
}

if ($path === '/admin/support-inquiries/settings' && $method === 'POST') {
    $webController->updateSupportInquirySettings($_POST);
}

if (preg_match('#^/admin/support-inquiries/([1-9][0-9]*)/answer$#', $path, $matches) === 1 && $method === 'POST') {
    $webController->answerSupportInquiry((int)$matches[1], $_POST);
}

if (preg_match('#^/admin/support-inquiries/([1-9][0-9]*)/close$#', $path, $matches) === 1 && $method === 'POST') {
    $webController->closeSupportInquiry((int)$matches[1], $_POST);
}

if ($path === '/notification-settings' && $routeMethod === 'GET') {
    $webController->notificationSettingsForm();
}

if ($path === '/notification-settings' && $method === 'POST') {
    $webController->saveNotificationSettings($_POST);
}

if ($path === '/notification-settings/send' && $method === 'POST') {
    $webController->sendNotificationNow($_POST);
}

if ($path === '/work-entries' && $routeMethod === 'GET') {
    $webController->workEntries($_GET);
}

if ($path === '/work-entries/create' && $routeMethod === 'GET') {
    $webController->createWorkEntryForm();
}

if ($path === '/work-entries/import' && $routeMethod === 'GET') {
    $webController->importWorkEntriesForm();
}

if ($path === '/work-entries/import/preview' && $method === 'POST') {
    $webController->previewWorkEntryImport($_POST);
}

if ($path === '/work-entries/import' && $method === 'POST') {
    $webController->saveWorkEntryImport($_POST);
}

if ($path === '/work-entries/search' && $routeMethod === 'GET') {
    $webController->searchWorkEntries($_GET);
}

if ($path === '/work-entries' && $method === 'POST') {
    $webController->createWorkEntry($_POST);
}

if (preg_match('#^/work-entries/([1-9][0-9]*)/edit$#', $path, $matches) === 1) {
    if ($routeMethod === 'GET') {
        $webController->editWorkEntryForm((int)$matches[1], $_GET);
    }

    if ($method === 'POST') {
        $webController->updateWorkEntry((int)$matches[1], $_POST);
    }
}

if (preg_match('#^/work-entries/([1-9][0-9]*)/delete$#', $path, $matches) === 1 && $method === 'POST') {
    $webController->deleteWorkEntry((int)$matches[1]);
}

http_response_code(404);
securityHeaders();
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
exit;

if ($path !== '/' && $path !== '/health') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

$checks = HealthCheck::run();
$dbStatus = $checks['status'];
$dbMessage = $checks['message'];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>근무시간 관리 시스템</title>
    <style>
        :root {
            --bg: #f7f8fb;
            --surface: #ffffff;
            --border: #d9dee8;
            --text: #1d2433;
            --muted: #5d687c;
            --ok: #0f7b3f;
            --fail: #b42318;
            --link: #185abc;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        main {
            width: min(920px, calc(100% - 32px));
            margin: 48px auto;
        }
        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            letter-spacing: 0;
        }
        p {
            margin: 0 0 24px;
            color: var(--muted);
            line-height: 1.6;
        }
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }
        .status {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }
        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: <?= $dbStatus === 'ok' ? 'var(--ok)' : 'var(--fail)' ?>;
            flex: 0 0 auto;
        }
        .status strong {
            display: block;
            margin-bottom: 4px;
        }
        .status span {
            color: var(--muted);
            font-size: 14px;
            word-break: break-word;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }
        th {
            width: 220px;
            color: var(--muted);
            font-weight: 600;
            background: #fbfcfe;
        }
        tr:last-child th,
        tr:last-child td {
            border-bottom: 0;
        }
        a {
            color: var(--link);
            text-decoration: none;
            font-weight: 600;
        }
        a:hover { text-decoration: underline; }
        .actions {
            margin-top: 16px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
<main>
    <h1>근무시간 관리 시스템</h1>
    <p>PHP 8.4, MySQL 8.0, PDO, .env 기반 로컬 실행 환경 점검 화면입니다.</p>

    <section class="panel" aria-label="환경 점검">
        <div class="status">
            <span class="dot" aria-hidden="true"></span>
            <div>
                <strong>Database: <?= h(strtoupper($dbStatus)) ?></strong>
                <span><?= h($dbMessage) ?></span>
            </div>
        </div>
        <table>
            <tbody>
            <?php foreach ($checks as $label => $value): ?>
                <tr>
                    <th><?= h(ucwords(str_replace('_', ' ', $label))) ?></th>
                    <td><?= h($value) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="actions">
        <a href="/health.json">JSON health check</a>
        <a href="/index.html">기존 AI 근무시간 파서 열기</a>
    </div>
</main>
</body>
</html>
