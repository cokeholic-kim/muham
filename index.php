<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Config/Env.php';
require_once __DIR__ . '/app/Services/SessionService.php';
require_once __DIR__ . '/app/Database/Database.php';
require_once __DIR__ . '/app/Database/HealthCheck.php';
require_once __DIR__ . '/app/Services/AuditLogService.php';
require_once __DIR__ . '/app/Services/AuthService.php';
require_once __DIR__ . '/app/Services/WorkEntryService.php';
require_once __DIR__ . '/app/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/app/Controllers/AuthController.php';
require_once __DIR__ . '/app/Controllers/WorkEntryController.php';
require_once __DIR__ . '/app/Controllers/WebController.php';

use App\Controllers\AuthController;
use App\Controllers\WebController;
use App\Controllers\WorkEntryController;
use App\Config\Env;
use App\Database\HealthCheck;
use App\Middleware\AuthMiddleware;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\WorkEntryService;

Env::load(__DIR__ . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Seoul'));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function jsonResponse(array $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @return array<string, mixed>
 */
function readJsonPayload(): array
{
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
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
        $message === '삭제된 근무 기록을 찾을 수 없습니다.'
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
    $authService = new AuthService();
    $auditLogService = new AuditLogService();
    $authMiddleware = new AuthMiddleware($authService);
    $requestContext = requestContext();
    $authController = new AuthController(
        $authService,
        $authMiddleware,
        $auditLogService,
        $requestContext
    );
    $workEntryController = new WorkEntryController(
        new WorkEntryService($auditLogService, $requestContext),
        $authMiddleware
    );

    try {
        $workEntryId = $path === '/api/work-entries/summary'
            ? null
            : WorkEntryController::routeId($path, '/api/work-entries/');
        $result = match ([$method, $path]) {
            ['POST', '/api/auth/signup'] => $authController->signup(readJsonPayload()),
            ['POST', '/api/auth/login'] => $authController->login(readJsonPayload()),
            ['POST', '/api/auth/logout'] => $authController->logout(),
            ['GET', '/api/me'] => $authController->me(),
            ['POST', '/api/work-entries'] => $workEntryController->create(readJsonPayload()),
            ['GET', '/api/work-entries'] => $workEntryController->list($_GET),
            ['GET', '/api/work-entries/summary'] => $workEntryController->summary($_GET),
            default => null,
        };

        if ($result === null && $workEntryId !== null && $method === 'PATCH') {
            $result = $workEntryController->update($workEntryId, readJsonPayload());
        }

        if ($result === null && $workEntryId !== null && $method === 'DELETE') {
            $result = $workEntryController->delete($workEntryId);
        }

        if ($result === null) {
            jsonResponse(['message' => 'Not Found'], 404);
        }

        jsonResponse($result['body'], $result['status']);
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
    jsonResponse(HealthCheck::run());
}

if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}

$authService = new AuthService();
$auditLogService = new AuditLogService();
$authMiddleware = new AuthMiddleware($authService);
$requestContext = requestContext();
$webController = new WebController(
    new AuthController($authService, $authMiddleware, $auditLogService, $requestContext),
    $authMiddleware,
    new WorkEntryService($auditLogService, $requestContext)
);

if ($path === '/' && $method === 'GET') {
    $webController->home();
}

if ($path === '/health' && $method === 'GET') {
    $webController->health();
}

if ($path === '/login' && $method === 'GET') {
    $webController->loginForm();
}

if ($path === '/login' && $method === 'POST') {
    $webController->login($_POST);
}

if ($path === '/signup' && $method === 'GET') {
    $webController->signupForm();
}

if ($path === '/signup' && $method === 'POST') {
    $webController->signup($_POST);
}

if ($path === '/logout' && $method === 'POST') {
    $webController->logout();
}

if ($path === '/work-entries' && $method === 'GET') {
    $webController->workEntries($_GET);
}

if ($path === '/work-entries' && $method === 'POST') {
    $webController->createWorkEntry($_POST);
}

if (preg_match('#^/work-entries/([1-9][0-9]*)/edit$#', $path, $matches) === 1) {
    if ($method === 'GET') {
        $webController->editWorkEntryForm((int)$matches[1]);
    }

    if ($method === 'POST') {
        $webController->updateWorkEntry((int)$matches[1], $_POST);
    }
}

if (preg_match('#^/work-entries/([1-9][0-9]*)/delete$#', $path, $matches) === 1 && $method === 'POST') {
    $webController->deleteWorkEntry((int)$matches[1]);
}

http_response_code(404);
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
