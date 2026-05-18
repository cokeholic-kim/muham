<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Config/Env.php';
require_once __DIR__ . '/app/Database/Database.php';
require_once __DIR__ . '/app/Database/HealthCheck.php';

use App\Config\Env;
use App\Database\HealthCheck;

Env::load(__DIR__ . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Asia/Seoul'));

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health.json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(HealthCheck::run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/favicon.ico') {
    http_response_code(204);
    exit;
}

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
