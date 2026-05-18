<?php
declare(strict_types=1);

function loadEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
        $key = trim($key);
        $value = trim($value);

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

loadEnv(__DIR__ . '/.env');

$appTimezone = envValue('APP_TIMEZONE', 'Asia/Seoul');
date_default_timezone_set($appTimezone);

$dbStatus = 'failed';
$dbMessage = '';
$dbVersion = '';

try {
    $charset = envValue('DB_CHARSET', 'utf8mb4');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        envValue('DB_HOST', 'mysql'),
        envValue('DB_PORT', '3306'),
        envValue('DB_DATABASE', 'muham_worktime'),
        $charset
    );

    $pdo = new PDO($dsn, envValue('DB_USERNAME', 'muham'), envValue('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $stmt = $pdo->query('SELECT VERSION() AS version, DATABASE() AS database_name');
    $row = $stmt === false ? [] : $stmt->fetch();

    $dbStatus = 'ok';
    $dbVersion = (string)($row['version'] ?? '');
    $dbMessage = 'PDO MySQL connection is ready.';
} catch (Throwable $e) {
    $dbMessage = $e->getMessage();
}

$checks = [
    'PHP Version' => PHP_VERSION,
    'PDO Loaded' => extension_loaded('pdo') ? 'yes' : 'no',
    'PDO MySQL Loaded' => extension_loaded('pdo_mysql') ? 'yes' : 'no',
    'Database Host' => envValue('DB_HOST', 'mysql') . ':' . envValue('DB_PORT', '3306'),
    'Database Name' => envValue('DB_DATABASE', 'muham_worktime'),
    'MySQL Version' => $dbVersion !== '' ? $dbVersion : '-',
    'Timezone' => date_default_timezone_get(),
    'Checked At' => date('Y-m-d H:i:s'),
];
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
                <strong>Database: <?= htmlspecialchars(strtoupper($dbStatus), ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars($dbMessage, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <table>
            <tbody>
            <?php foreach ($checks as $label => $value): ?>
                <tr>
                    <th><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                    <td><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <div class="actions">
        <a href="/index.html">기존 AI 근무시간 파서 열기</a>
    </div>
</main>
</body>
</html>
