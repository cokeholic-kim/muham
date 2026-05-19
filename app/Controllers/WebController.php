<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database\HealthCheck;
use App\Middleware\AuthMiddleware;
use App\Services\SessionService;
use App\Services\WorkEntryService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebController
{
    public function __construct(
        private readonly AuthController $authController,
        private readonly AuthMiddleware $authMiddleware,
        private readonly WorkEntryService $workEntryService
    ) {
    }

    public function home(): never
    {
        $this->redirect(SessionService::userId() === null ? '/login' : '/work-entries');
    }

    public function loginForm(): never
    {
        if (SessionService::userId() !== null) {
            $this->redirect('/work-entries');
        }

        $this->render('로그인', $this->authPage('로그인', '/login', '로그인', '계정이 없나요?', '/signup', '회원가입'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function login(array $payload): never
    {
        try {
            $this->authController->login($payload);
            $this->flash('success', '로그인되었습니다.');
            $this->redirect('/work-entries');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect('/login');
        }
    }

    public function signupForm(): never
    {
        if (SessionService::userId() !== null) {
            $this->redirect('/work-entries');
        }

        $this->render('회원가입', $this->authPage('회원가입', '/signup', '회원가입', '이미 계정이 있나요?', '/login', '로그인'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function signup(array $payload): never
    {
        try {
            $this->authController->signup($payload);
            $this->flash('success', '회원가입이 완료되었습니다.');
            $this->redirect('/work-entries');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect('/signup');
        }
    }

    public function logout(): never
    {
        $this->authController->logout();
        $this->flash('success', '로그아웃되었습니다.');
        $this->redirect('/login');
    }

    /**
     * @param array<string, mixed> $query
     */
    public function workEntries(array $query): never
    {
        $user = $this->requireWebUser();
        [$from, $to] = $this->monthRange($query);

        try {
            $entries = $this->workEntryService->list($user, ['from' => $from, 'to' => $to]);
            $summary = $this->workEntryService->summary($user, ['from' => $from, 'to' => $to]);
        } catch (Throwable $e) {
            $entries = [];
            $summary = $this->emptySummary($from, $to);
            $this->flash('danger', $e->getMessage());
        }

        $this->render('근무시간', $this->workEntriesPage($user, $from, $to, $entries, $summary));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createWorkEntry(array $payload): never
    {
        $user = $this->requireWebUser();

        try {
            $this->workEntryService->create($user, $this->formToWorkPayload($payload));
            $this->flash('success', '근무 기록이 저장되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($this->backToWorkEntries());
    }

    public function editWorkEntryForm(int $id): never
    {
        $user = $this->requireWebUser();
        $entry = $this->workEntryService->find($user, $id);

        if ($entry === null) {
            $this->flash('danger', '근무 기록을 찾을 수 없습니다.');
            $this->redirect('/work-entries');
        }

        $this->render('근무 기록 수정', $this->editWorkEntryPage($entry));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateWorkEntry(int $id, array $payload): never
    {
        $user = $this->requireWebUser();

        try {
            $this->workEntryService->update($user, $id, $this->formToWorkPayload($payload));
            $this->flash('success', '근무 기록이 수정되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($this->backToWorkEntries());
    }

    public function deleteWorkEntry(int $id): never
    {
        $user = $this->requireWebUser();

        try {
            $this->workEntryService->delete($user, $id);
            $this->flash('success', '근무 기록이 삭제되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($this->backToWorkEntries());
    }

    public function health(): never
    {
        $checks = HealthCheck::run();
        $rows = '';

        foreach ($checks as $label => $value) {
            $rows .= sprintf(
                '<tr><th>%s</th><td>%s</td></tr>',
                $this->h(ucwords(str_replace('_', ' ', (string)$label))),
                $this->h((string)$value)
            );
        }

        $statusClass = $checks['status'] === 'ok' ? 'success' : 'danger';
        $body = sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">환경 점검</h1><p class="text-secondary mb-0">PHP, PDO, MySQL 연결 상태입니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">근무시간</a>
            </div>
            <div class="alert alert-%s"><strong>Database: %s</strong><div>%s</div></div>
            <div class="table-responsive"><table class="table table-sm align-middle">%s</table></div>',
            $statusClass,
            $this->h(strtoupper((string)$checks['status'])),
            $this->h((string)$checks['message']),
            $rows
        );

        $this->render('환경 점검', $body);
    }

    private function authPage(string $title, string $action, string $button, string $altText, string $altHref, string $altLabel): string
    {
        $nameField = $action === '/signup'
            ? '<div class="mb-3"><label class="form-label" for="name">이름</label><input class="form-control" id="name" name="name" required maxlength="100"></div>'
            : '';

        return sprintf(
            '<div class="row justify-content-center">
                <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                    <h1 class="h3 mb-3">%s</h1>
                    <form class="border rounded-2 bg-white p-4" method="post" action="%s">
                        %s
                        <div class="mb-3"><label class="form-label" for="email">이메일</label><input class="form-control" id="email" type="email" name="email" required autocomplete="email"></div>
                        <div class="mb-3"><label class="form-label" for="password">비밀번호</label><input class="form-control" id="password" type="password" name="password" required autocomplete="current-password" minlength="8"></div>
                        <button class="btn btn-primary w-100" type="submit">%s</button>
                    </form>
                    <div class="mt-3 text-center text-secondary">%s <a href="%s">%s</a></div>
                </div>
            </div>',
            $this->h($title),
            $this->h($action),
            $nameField,
            $this->h($button),
            $this->h($altText),
            $this->h($altHref),
            $this->h($altLabel)
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $summary
     */
    private function workEntriesPage(array $user, string $from, string $to, array $entries, array $summary): string
    {
        $rows = '';

        foreach ($entries as $entry) {
            $rows .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td>%s</td>
                    <td>%s</td>
                    <td class="text-end">%s</td>
                    <td class="text-end">%s</td>
                    <td>%s</td>
                    <td class="text-end">%s</td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary" href="/work-entries/%d/edit">수정</a>
                        <form class="d-inline" method="post" action="/work-entries/%d/delete" onsubmit="return confirm(\'삭제하시겠습니까?\')"><button class="btn btn-sm btn-outline-danger" type="submit">삭제</button></form>
                    </td>
                </tr>',
                $this->h((string)$entry['work_date']),
                $this->h(substr((string)$entry['start_at'], 11, 5)),
                $this->h(substr((string)$entry['end_at'], 11, 5)),
                $this->h((string)$entry['break_minutes']),
                $this->h($this->formatMinutes((int)$entry['work_minutes'])),
                $this->h((string)($entry['memo'] ?? '')),
                $this->h((string)$entry['version']),
                (int)$entry['id'],
                (int)$entry['id']
            );
        }

        if ($rows === '') {
            $rows = '<tr><td class="text-center text-secondary py-4" colspan="8">조회된 근무 기록이 없습니다.</td></tr>';
        }

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무시간</h1><p class="text-secondary mb-0">%s · %s</p></div>
                <form method="post" action="/logout"><button class="btn btn-outline-secondary" type="submit">로그아웃</button></form>
            </div>
            <section class="border rounded-2 bg-white p-3 mb-3">
                <form class="row g-3 align-items-end" method="get" action="/work-entries">
                    <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="from">시작일</label><input class="form-control" id="from" type="date" name="from" value="%s" required></div>
                    <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="to">종료일</label><input class="form-control" id="to" type="date" name="to" value="%s" required></div>
                    <div class="col-12 col-lg-2"><button class="btn btn-primary w-100" type="submit">조회</button></div>
                </form>
            </section>
            <div class="row g-3 mb-3">
                %s
            </div>
            <section class="border rounded-2 bg-white p-3 mb-3">
                <h2 class="h5 mb-3">근무시간 입력</h2>
                <form class="row g-3" method="post" action="/work-entries">
                    <input type="hidden" name="from" value="%s"><input type="hidden" name="to" value="%s">
                    <div class="col-12 col-md-3"><label class="form-label" for="workDate">근무일</label><input class="form-control" id="workDate" type="date" name="workDate" required></div>
                    <div class="col-6 col-md-2"><label class="form-label" for="startTime">시작</label><input class="form-control" id="startTime" type="time" name="startTime" required></div>
                    <div class="col-6 col-md-2"><label class="form-label" for="endTime">종료</label><input class="form-control" id="endTime" type="time" name="endTime" required></div>
                    <div class="col-12 col-md-2"><label class="form-label" for="breakMinutes">휴게</label><input class="form-control" id="breakMinutes" type="number" name="breakMinutes" min="0" value="0" required></div>
                    <div class="col-12 col-md-3"><label class="form-label" for="memo">메모</label><input class="form-control" id="memo" name="memo" maxlength="2000"></div>
                    <div class="col-12"><button class="btn btn-success" type="submit">저장</button></div>
                </form>
            </section>
            <section class="border rounded-2 bg-white">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>근무일</th><th>시작</th><th>종료</th><th class="text-end">휴게</th><th class="text-end">실근무</th><th>메모</th><th class="text-end">버전</th><th class="text-end">작업</th></tr></thead>
                        <tbody>%s</tbody>
                    </table>
                </div>
            </section>',
            $this->h((string)$user['name']),
            $this->h((string)$user['email']),
            $this->h($from),
            $this->h($to),
            $this->summaryCards($summary),
            $this->h($from),
            $this->h($to),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function editWorkEntryPage(array $entry): string
    {
        $date = (string)$entry['work_date'];

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무 기록 수정</h1><p class="text-secondary mb-0">%s</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">목록</a>
            </div>
            <form class="border rounded-2 bg-white p-3 row g-3" method="post" action="/work-entries/%d/edit">
                <div class="col-12 col-md-3"><label class="form-label" for="workDate">근무일</label><input class="form-control" id="workDate" type="date" name="workDate" value="%s" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="startTime">시작</label><input class="form-control" id="startTime" type="time" name="startTime" value="%s" required></div>
                <div class="col-6 col-md-2"><label class="form-label" for="endTime">종료</label><input class="form-control" id="endTime" type="time" name="endTime" value="%s" required></div>
                <div class="col-12 col-md-2"><label class="form-label" for="breakMinutes">휴게</label><input class="form-control" id="breakMinutes" type="number" name="breakMinutes" min="0" value="%s" required></div>
                <div class="col-12 col-md-3"><label class="form-label" for="memo">메모</label><input class="form-control" id="memo" name="memo" value="%s" maxlength="2000"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">수정</button></div>
            </form>',
            $this->h($date),
            (int)$entry['id'],
            $this->h($date),
            $this->h(substr((string)$entry['start_at'], 11, 5)),
            $this->h(substr((string)$entry['end_at'], 11, 5)),
            $this->h((string)$entry['break_minutes']),
            $this->h((string)($entry['memo'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function summaryCards(array $summary): string
    {
        $items = [
            ['근무일', (string)$summary['total_work_days'] . '일'],
            ['기록', (string)$summary['total_entries'] . '건'],
            ['전체', $this->formatMinutes((int)$summary['gross_minutes'])],
            ['휴게', $this->formatMinutes((int)$summary['break_minutes'])],
            ['실근무', $this->formatMinutes((int)$summary['work_minutes'])],
        ];
        $html = '';

        foreach ($items as [$label, $value]) {
            $html .= sprintf(
                '<div class="col-6 col-lg"><div class="border rounded-2 bg-white p-3 h-100"><div class="text-secondary small">%s</div><div class="fs-5 fw-semibold">%s</div></div></div>',
                $this->h($label),
                $this->h($value)
            );
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function formToWorkPayload(array $payload): array
    {
        $workDate = $this->stringInput($payload, 'workDate');

        return [
            'workDate' => $workDate,
            'startAt' => $workDate . ' ' . $this->stringInput($payload, 'startTime'),
            'endAt' => $workDate . ' ' . $this->stringInput($payload, 'endTime'),
            'breakMinutes' => (int)($payload['breakMinutes'] ?? 0),
            'memo' => isset($payload['memo']) ? (string)$payload['memo'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringInput(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s 값이 필요합니다.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: string}
     */
    private function monthRange(array $query): array
    {
        $today = date('Y-m-d');
        $from = isset($query['from']) && is_string($query['from']) && $query['from'] !== ''
            ? $query['from']
            : date('Y-m-01');
        $to = isset($query['to']) && is_string($query['to']) && $query['to'] !== ''
            ? $query['to']
            : date('Y-m-t', strtotime($today));

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(string $from, string $to): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'total_entries' => 0,
            'total_work_days' => 0,
            'gross_minutes' => 0,
            'break_minutes' => 0,
            'work_minutes' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireWebUser(): array
    {
        try {
            return $this->authMiddleware->requireUser();
        } catch (RuntimeException $e) {
            $this->flash('danger', '로그인이 필요합니다.');
            $this->redirect('/login');
        }
    }

    private function backToWorkEntries(): string
    {
        $from = isset($_POST['from']) && is_string($_POST['from']) ? $_POST['from'] : date('Y-m-01');
        $to = isset($_POST['to']) && is_string($_POST['to']) ? $_POST['to'] : date('Y-m-t');

        return '/work-entries?from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
    }

    private function render(string $title, string $body): never
    {
        SessionService::start();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $flashHtml = '';

        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            $flashHtml = sprintf(
                '<div class="alert alert-%s" role="alert">%s</div>',
                $this->h((string)$flash['type']),
                $this->h((string)$flash['message'])
            );
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $this->h($title) . ' · 근무시간 관리</title>';
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '<style>body{background:#f6f7f9}.navbar{border-bottom:1px solid #dee2e6}.container-narrow{max-width:1120px}.table th,.table td{white-space:nowrap}.table td:nth-child(6){white-space:normal;min-width:160px}@media(max-width:575.98px){.container-narrow{padding-left:14px;padding-right:14px}.table th,.table td{font-size:.875rem}}</style>';
        echo '</head><body><nav class="navbar bg-white"><div class="container container-narrow"><a class="navbar-brand fw-semibold" href="/work-entries">근무시간 관리</a><div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="/health">상태</a><a class="btn btn-sm btn-outline-secondary" href="/index.html">AI 파서</a></div></div></nav>';
        echo '<main class="container container-narrow py-4">' . $flashHtml . $body . '</main>';
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
        echo '</body></html>';
        exit;
    }

    private function flash(string $type, string $message): void
    {
        SessionService::start();
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
