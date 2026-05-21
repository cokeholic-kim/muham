<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Database\HealthCheck;
use App\Middleware\AuthMiddleware;
use App\Services\AiCredentialService;
use App\Services\AuditLogService;
use App\Services\CsrfService;
use App\Services\NotificationSettingService;
use App\Services\SessionService;
use App\Services\WorkEntryService;
use App\Services\WorkEntryImportService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WebController
{
    public function __construct(
        private readonly AuthController $authController,
        private readonly AuthMiddleware $authMiddleware,
        private readonly WorkEntryService $workEntryService,
        private readonly WorkEntryImportService $workEntryImportService,
        private readonly AiCredentialService $aiCredentialService,
        private readonly NotificationSettingService $notificationSettingService,
        private readonly AuditLogService $auditLogService
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
        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/login');
        }

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
        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/signup');
        }

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
        if (!$this->validateCsrf($_POST)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/work-entries');
        }

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

        try {
            $entries = $this->workEntryService->recent($user, 10);
        } catch (Throwable $e) {
            $entries = [];
            $this->flash('danger', $e->getMessage());
        }

        $this->render('근무 기록', $this->workEntriesHomePage($user, $entries));
    }

    public function createWorkEntryForm(): never
    {
        $this->requireWebUser();
        $this->render('근무시간 입력', $this->createWorkEntryPage());
    }

    public function importWorkEntriesForm(): never
    {
        $user = $this->requireWebUser();
        $this->render('근무시간 일괄 입력', $this->importWorkEntriesPage($this->aiCredentialService->findForUser($user)));
    }

    /**
     * @param array<string, mixed> $query
     */
    public function searchWorkEntries(array $query): never
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

        $this->render('근무시간 조회', $this->searchWorkEntriesPage($from, $to, $entries, $summary));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createWorkEntry(array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/work-entries/create');
        }

        try {
            $this->workEntryService->create($user, $this->formToWorkPayload($payload));
            $this->flash('success', '근무 기록이 저장되었습니다.');
            $this->redirect('/work-entries');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect('/work-entries/create');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function previewWorkEntryImport(array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/work-entries/import');
        }

        try {
            if (isset($payload['apiKey']) && is_string($payload['apiKey']) && trim($payload['apiKey']) !== '') {
                $this->aiCredentialService->saveForUser($user, $payload);
            }

            $result = $this->workEntryImportService->preview($user, $payload);
            $this->render('근무시간 일괄 입력 확인', $this->importPreviewPage($result['entries'], $result['source']));
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect('/work-entries/import');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveWorkEntryImport(array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/work-entries/import');
        }

        try {
            $entries = $this->importEntriesFromPayload($payload);
            $created = $this->workEntryService->bulkCreate($user, $entries);
            [$from, $to] = $this->entryRange($created);
            $this->flash('success', sprintf('%d건의 근무 기록을 저장했습니다.', count($created)));
            $this->redirect('/work-entries/search?from=' . rawurlencode($from) . '&to=' . rawurlencode($to));
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect('/work-entries/import');
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public function editWorkEntryForm(int $id, array $query): never
    {
        $user = $this->requireWebUser();
        $entry = $this->workEntryService->find($user, $id);

        if ($entry === null) {
            $this->flash('danger', '근무 기록을 찾을 수 없습니다.');
            $this->redirect('/work-entries');
        }

        $returnTo = $this->returnPathFromPayload($query, '/work-entries');
        $this->render('근무 기록 수정', $this->editWorkEntryPage($entry, $returnTo));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateWorkEntry(int $id, array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/work-entries/' . $id . '/edit');
        }

        try {
            $this->workEntryService->update($user, $id, $this->formToWorkPayload($payload));
            $this->flash('success', '근무 기록이 수정되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($this->returnPathFromPayload($payload, '/work-entries'));
    }

    public function deleteWorkEntry(int $id): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($_POST)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect($this->returnPathFromPayload($_POST, '/work-entries'));
        }

        try {
            $this->workEntryService->delete($user, $id);
            $this->flash('success', '근무 기록이 삭제되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($this->returnPathFromPayload($_POST, '/work-entries'));
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

    public function notificationSettingsForm(): never
    {
        $user = $this->requireWebUser();
        $setting = $this->notificationSettingService->findForUser($user);

        $this->render('정기 발송 설정', $this->notificationSettingsPage($setting));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveNotificationSettings(array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/notification-settings');
        }

        try {
            $before = $this->notificationSettingService->findForUser($user);
            $setting = $this->notificationSettingService->saveForUser($user, $payload);
            $this->auditLogService->record(
                (int)$user['id'],
                (int)$user['id'],
                'save_notification_setting',
                'notification_setting',
                (int)$setting['id'],
                $this->settingForAudit($before),
                $this->settingForAudit($setting),
                $this->requestContext()
            );
            $this->flash('success', '정기 발송 설정이 저장되었습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect('/notification-settings');
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
                        %s
                        <div class="mb-3"><label class="form-label" for="email">이메일</label><input class="form-control" id="email" type="email" name="email" required autocomplete="email"></div>
                        <div class="mb-3"><label class="form-label" for="password">비밀번호</label><input class="form-control" id="password" type="password" name="password" required autocomplete="current-password" minlength="10"></div>
                        <button class="btn btn-primary w-100" type="submit">%s</button>
                    </form>
                    <div class="mt-3 text-center text-secondary">%s <a href="%s">%s</a></div>
                </div>
            </div>',
            $this->h($title),
            $this->h($action),
            CsrfService::input(),
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
     */
    private function workEntriesHomePage(array $user, array $entries): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무 기록</h1><p class="text-secondary mb-0">%s · %s</p></div>
                <form method="post" action="/logout">%s<button class="btn btn-outline-secondary" type="submit">로그아웃</button></form>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-3"><a class="btn btn-success w-100 py-3" href="/work-entries/create">근무시간 입력</a></div>
                <div class="col-12 col-md-3"><a class="btn btn-primary w-100 py-3" href="/work-entries/search">근무시간 조회</a></div>
                <div class="col-12 col-md-3"><a class="btn btn-outline-primary w-100 py-3" href="/work-entries/import">일괄 입력</a></div>
                <div class="col-12 col-md-3"><a class="btn btn-dark w-100 py-3" href="/notification-settings">정기 발송 설정</a></div>
            </div>
            <section class="border rounded-2 bg-white">
                <div class="d-flex justify-content-between align-items-center gap-3 p-3 border-bottom flex-wrap">
                    <h2 class="h5 mb-0">최근 근무 기록</h2>
                    <a class="btn btn-sm btn-outline-primary" href="/work-entries/search">전체 조회</a>
                </div>
                %s
            </section>',
            $this->h((string)$user['name']),
            $this->h((string)$user['email']),
            CsrfService::input(),
            $this->entriesTable($entries, '/work-entries')
        );
    }

    private function createWorkEntryPage(): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무시간 입력</h1><p class="text-secondary mb-0">근무일, 시작/종료 시간, 휴게 시간을 입력합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3">
                <form class="row g-3" method="post" action="/work-entries">
                    %s
                    %s
                    <div class="col-12"><button class="btn btn-success" type="submit">저장</button></div>
                </form>
            </section>',
            CsrfService::input(),
            $this->workEntryFormFields()
        );
    }

    /**
     * @param array<string, mixed>|null $setting
     */
    private function importWorkEntriesPage(?array $setting): string
    {
        $provider = (string)($setting['provider'] ?? 'gemini');
        $model = (string)($setting['model'] ?? $this->aiCredentialService->defaultModel($provider));
        $hint = isset($setting['api_key_hint']) && is_string($setting['api_key_hint'])
            ? '저장됨: ' . $setting['api_key_hint']
            : 'API Key 입력';
        $sample = "04/04 5:30 - 16:00\n04/05 14:00 - 16:30\n\n04/13 9:30 - 10:00 , 14:15 - 16:15";

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무시간 일괄 입력</h1><p class="text-secondary mb-0">정규 포맷은 빠르게 해석하고, 애매한 텍스트는 저장된 AI Key로 변환합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3">
                <form class="row g-3" method="post" action="/work-entries/import/preview">
                    %s
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="baseYear">기준 연도</label>
                        <input class="form-control" id="baseYear" name="baseYear" inputmode="numeric" value="%s" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="parserMode">변환 방식</label>
                        <select class="form-select" id="parserMode" name="parserMode">
                            <option value="auto">정규 포맷 우선</option>
                            <option value="pattern">정규 포맷만</option>
                            <option value="ai">AI로 변환</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="provider">AI Provider</label>
                        <select class="form-select" id="provider" name="provider">
                            <option value="gemini"%s>Gemini</option>
                            <option value="openai"%s>OpenAI</option>
                            <option value="anthropic"%s>Anthropic</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="model">모델</label>
                        <input class="form-control" id="model" name="model" value="%s" maxlength="120">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="apiKey">AI API Key</label>
                        <input class="form-control" id="apiKey" type="password" name="apiKey" placeholder="%s" autocomplete="off">
                        <div class="form-text">입력한 Key는 서버에서 암호화해 저장합니다. 비워두면 저장된 Key를 사용합니다.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="rawText">근무시간 텍스트</label>
                        <textarea class="form-control font-monospace" id="rawText" name="rawText" rows="12" required placeholder="%s"></textarea>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">변환 미리보기</button></div>
                </form>
            </section>',
            CsrfService::input(),
            $this->h(date('Y')),
            $provider === 'gemini' ? ' selected' : '',
            $provider === 'openai' ? ' selected' : '',
            $provider === 'anthropic' ? ' selected' : '',
            $this->h($model),
            $this->h($hint),
            $this->h($sample)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function importPreviewPage(array $entries, string $source): string
    {
        $rows = '';
        $hidden = '';

        foreach ($entries as $index => $entry) {
            $start = substr((string)$entry['startAt'], 11, 5);
            $end = substr((string)$entry['endAt'], 11, 5);
            $minutes = $this->minutesBetween((string)$entry['startAt'], (string)$entry['endAt']);
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td class="text-end">%s</td><td>%s</td></tr>',
                $this->h((string)$entry['workDate']),
                $this->h($start),
                $this->h($end),
                $this->h($this->formatMinutes($minutes)),
                $this->h((string)($entry['memo'] ?? ''))
            );

            foreach (['workDate', 'startAt', 'endAt', 'breakMinutes', 'memo'] as $field) {
                $hidden .= sprintf(
                    '<input type="hidden" name="entries[%d][%s]" value="%s">',
                    $index,
                    $this->h($field),
                    $this->h((string)($entry[$field] ?? ''))
                );
            }
        }

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">일괄 입력 확인</h1><p class="text-secondary mb-0">%s 방식으로 %d건을 변환했습니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries/import">다시 입력</a>
            </div>
            <form method="post" action="/work-entries/import">
                %s
                %s
                <section class="border rounded-2 bg-white mb-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>근무일</th><th>시작</th><th>종료</th><th class="text-end">시간</th><th>메모</th></tr></thead>
                            <tbody>%s</tbody>
                        </table>
                    </div>
                </section>
                <button class="btn btn-success" type="submit">확인 후 일괄 저장</button>
            </form>',
            $this->h($source === 'ai' ? 'AI' : '정규 포맷'),
            count($entries),
            CsrfService::input(),
            $hidden,
            $rows
        );
    }

    /**
     * @param array<string, mixed>|null $setting
     */
    private function notificationSettingsPage(?array $setting): string
    {
        $channel = (string)($setting['channel'] ?? 'telegram');
        $summaryPeriodType = (string)($setting['summary_period_type'] ?? 'previous_month');
        $customPeriodFrom = (string)($setting['custom_period_from'] ?? date('Y-m-01'));
        $customPeriodTo = (string)($setting['custom_period_to'] ?? date('Y-m-d'));
        $monthlySendDay = (int)($setting['monthly_send_day'] ?? (int)date('j'));
        $isActive = $setting === null || (int)$setting['is_active'] === 1;
        $appUrl = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');
        $triggerDate = date('Y-m-d');
        $examplePayload = json_encode(
            [
                'triggerDate' => $triggerDate,
                'requestId' => 'scheduled-work-summary-' . $triggerDate,
            ],
            JSON_UNESCAPED_SLASHES
        );

        if ($examplePayload === false) {
            $examplePayload = '{"triggerDate":"' . $triggerDate . '"}';
        }

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">정기 발송 설정</h1><p class="text-secondary mb-0">외부 서버가 매일 웹훅을 호출하면 발송일이 맞는 설정만 근무 요약을 전송합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3 mb-3">
                <form class="row g-3" method="post" action="/notification-settings">
                    %s
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="channel">전송 채널</label>
                        <select class="form-select" id="channel" name="channel" data-notification-channel required>
                            <option value="telegram"%s>Telegram</option>
                            <option value="discord"%s>Discord</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="monthlySendDay">매월 발송일</label>
                        <select class="form-select" id="monthlySendDay" name="monthlySendDay" required>%s</select>
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch pb-2">
                            <input class="form-check-input" id="isActive" type="checkbox" name="isActive" value="1"%s>
                            <label class="form-check-label" for="isActive">활성화</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label d-block">요약 기간 기준</label>
                        <div class="d-flex flex-wrap gap-2" data-summary-period-options>%s</div>
                        <div class="form-text">외부 서버는 매일 실행 신호만 보내고, 실제 요약 기간은 선택한 기준으로 자동 계산합니다.</div>
                    </div>
                    <div class="col-12 col-md-4%s" data-custom-period-panel>
                        <label class="form-label" for="customPeriodFrom">직접 지정 시작일</label>
                        <input class="form-control" id="customPeriodFrom" type="date" name="customPeriodFrom" value="%s">
                    </div>
                    <div class="col-12 col-md-4%s" data-custom-period-panel>
                        <label class="form-label" for="customPeriodTo">직접 지정 종료일</label>
                        <input class="form-control" id="customPeriodTo" type="date" name="customPeriodTo" value="%s">
                    </div>
                    <div class="col-12"><hr></div>
                    <div class="col-12 col-lg-6%s" data-channel-panel="telegram">
                        <h2 class="h6 mb-3">Telegram</h2>
                        <label class="form-label" for="telegramBotToken">Bot token</label>
                        <input class="form-control mb-2" id="telegramBotToken" type="password" name="telegramBotToken" placeholder="%s"%s>
                        <label class="form-label" for="telegramChatId">Chat ID</label>
                        <input class="form-control" id="telegramChatId" name="telegramChatId" placeholder="%s"%s>
                    </div>
                    <div class="col-12 col-lg-6%s" data-channel-panel="discord">
                        <h2 class="h6 mb-3">Discord</h2>
                        <label class="form-label" for="discordWebhookUrl">Webhook URL</label>
                        <input class="form-control" id="discordWebhookUrl" type="password" name="discordWebhookUrl" placeholder="%s"%s>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">설정 저장</button></div>
                </form>
            </section>
            <section class="border rounded-2 bg-white p-3">
                <h2 class="h5 mb-3">외부 서버 호출 예시</h2>
                <pre class="bg-light border rounded-2 p-3 mb-0 overflow-auto"><code>curl -X POST %s/api/webhooks/work-summary \\
  -H "Content-Type: application/json" \\
  -H "X-Webhook-Secret: ${WEBHOOK_SHARED_SECRET}" \\
  -d \'%s\'</code></pre>
            </section>
            <script>
            (() => {
                const select = document.querySelector("[data-notification-channel]");
                if (!select) return;
                const panels = [...document.querySelectorAll("[data-channel-panel]")];
                const syncPanels = () => {
                    panels.forEach((panel) => {
                        const active = panel.dataset.channelPanel === select.value;
                        panel.classList.toggle("d-none", !active);
                        panel.querySelectorAll("input, select, textarea").forEach((input) => {
                            input.disabled = !active;
                            if (input.dataset.requiredWhenActive === "1") {
                                input.required = active;
                            }
                        });
                    });
                };
                select.addEventListener("change", syncPanels);
                syncPanels();
            })();
            (() => {
                const radios = [...document.querySelectorAll("input[name=\'summaryPeriodType\']")];
                const panels = [...document.querySelectorAll("[data-custom-period-panel]")];
                const syncCustomPeriod = () => {
                    const selected = radios.find((radio) => radio.checked)?.value;
                    const active = selected === "custom";
                    panels.forEach((panel) => {
                        panel.classList.toggle("d-none", !active);
                        panel.querySelectorAll("input").forEach((input) => {
                            input.disabled = !active;
                            input.required = active;
                        });
                    });
                };
                radios.forEach((radio) => radio.addEventListener("change", syncCustomPeriod));
                syncCustomPeriod();
            })();
            </script>',
            CsrfService::input(),
            $channel === 'telegram' ? ' selected' : '',
            $channel === 'discord' ? ' selected' : '',
            $this->dayOptions($monthlySendDay),
            $isActive ? ' checked' : '',
            $this->summaryPeriodOptions($summaryPeriodType),
            $summaryPeriodType === 'custom' ? '' : ' d-none',
            $this->h($customPeriodFrom),
            $summaryPeriodType === 'custom' ? '' : ' d-none',
            $this->h($customPeriodTo),
            $channel === 'telegram' ? '' : ' d-none',
            $this->h($this->secretPlaceholder($setting['telegram_bot_token'] ?? null, 'Bot token')),
            $this->requiredWhenEmpty($setting['telegram_bot_token'] ?? null),
            $this->h($this->secretPlaceholder($setting['telegram_chat_id'] ?? null, 'Chat ID')),
            $this->requiredWhenEmpty($setting['telegram_chat_id'] ?? null),
            $channel === 'discord' ? '' : ' d-none',
            $this->h($this->secretPlaceholder($setting['discord_webhook_url'] ?? null, 'Webhook URL')),
            $this->requiredWhenEmpty($setting['discord_webhook_url'] ?? null),
            $this->h($appUrl),
            $this->h($examplePayload)
        );
    }

    private function summaryPeriodOptions(string $selectedType): string
    {
        $options = [
            'previous_month' => '지난달',
            'current_month' => '이번달 현재까지',
            'previous_7_days' => '최근 7일',
            'custom' => '직접 지정',
        ];
        $html = '';

        foreach ($options as $value => $label) {
            $id = 'summaryPeriodType' . str_replace('_', '', ucwords($value, '_'));
            $html .= sprintf(
                '<input class="btn-check" type="radio" name="summaryPeriodType" id="%s" value="%s"%s>
                <label class="btn btn-outline-primary" for="%s">%s</label>',
                $this->h($id),
                $this->h($value),
                $value === $selectedType ? ' checked' : '',
                $this->h($id),
                $this->h($label)
            );
        }

        return $html;
    }

    private function dayOptions(int $selectedDay): string
    {
        $html = '';

        for ($day = 1; $day <= 31; $day++) {
            $selected = $day === $selectedDay ? ' selected' : '';
            $html .= sprintf('<option value="%d"%s>%d일</option>', $day, $selected, $day);
        }

        return $html;
    }

    private function secretPlaceholder(mixed $value, string $label): string
    {
        if (is_string($value) && $value !== '') {
            return '저장됨: ' . $this->notificationSettingService->mask($value);
        }

        return $label . ' 입력';
    }

    private function requiredWhenEmpty(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return '';
        }

        return ' data-required-when-active="1"';
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $summary
     */
    private function searchWorkEntriesPage(string $from, string $to, array $entries, array $summary): string
    {
        $returnTo = '/work-entries/search?from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무시간 조회</h1><p class="text-secondary mb-0">%s ~ %s</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3 mb-3">
                <form class="row g-3 align-items-end" method="get" action="/work-entries/search">
                    <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="from">시작일</label><input class="form-control" id="from" type="date" name="from" value="%s" required></div>
                    <div class="col-12 col-sm-6 col-lg-3"><label class="form-label" for="to">종료일</label><input class="form-control" id="to" type="date" name="to" value="%s" required></div>
                    <div class="col-12 col-sm-4 col-lg-2"><button class="btn btn-primary w-100" type="submit">조회</button></div>
                    <div class="col-6 col-sm-4 col-lg-2"><a class="btn btn-outline-secondary w-100" href="%s">이번달</a></div>
                    <div class="col-6 col-sm-4 col-lg-2"><a class="btn btn-outline-secondary w-100" href="%s">지난달</a></div>
                </form>
            </section>
            <div class="row g-3 mb-3">
                %s
            </div>
            <section class="border rounded-2 bg-white">
                %s
            </section>',
            $this->h($from),
            $this->h($to),
            $this->h($from),
            $this->h($to),
            $this->h($this->monthLink('this')),
            $this->h($this->monthLink('last')),
            $this->summaryCards($summary),
            $this->entriesTable($entries, $returnTo)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function entriesTable(array $entries, string $returnTo): string
    {
        return sprintf(
            '<div class="d-none d-md-block table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>근무일</th><th>시작</th><th>종료</th><th class="text-end">휴게</th><th class="text-end">실근무</th><th>메모</th><th class="text-end">버전</th><th class="text-end">작업</th></tr></thead>
                    <tbody>%s</tbody>
                </table>
            </div>
            <div class="d-md-none">%s</div>',
            $this->entryRows($entries, $returnTo),
            $this->entryCards($entries, $returnTo)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function entryRows(array $entries, string $returnTo): string
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
                        <a class="btn btn-sm btn-outline-secondary" href="/work-entries/%d/edit?returnTo=%s">수정</a>
                        <form class="d-inline" method="post" action="/work-entries/%d/delete" onsubmit="return confirm(\'삭제하시겠습니까?\')">%s<input type="hidden" name="returnTo" value="%s"><button class="btn btn-sm btn-outline-danger" type="submit">삭제</button></form>
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
                rawurlencode($returnTo),
                (int)$entry['id'],
                CsrfService::input(),
                $this->h($returnTo)
            );
        }

        if ($rows === '') {
            return '<tr><td class="text-center text-secondary py-4" colspan="8">조회된 근무 기록이 없습니다.</td></tr>';
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function entryCards(array $entries, string $returnTo): string
    {
        if ($entries === []) {
            return '<div class="text-center text-secondary py-4">조회된 근무 기록이 없습니다.</div>';
        }

        $cards = '<div class="list-group list-group-flush">';

        foreach ($entries as $entry) {
            $cards .= sprintf(
                '<div class="list-group-item p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <div class="fw-semibold">%s</div>
                            <div class="text-secondary small">%s ~ %s</div>
                        </div>
                        <div class="text-end">
                            <div class="fw-semibold">%s</div>
                            <div class="text-secondary small">휴게 %s분</div>
                        </div>
                    </div>
                    %s
                    <div class="d-flex justify-content-between align-items-center gap-2 mt-3">
                        <span class="text-secondary small">버전 %s</span>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary" href="/work-entries/%d/edit?returnTo=%s">수정</a>
                            <form method="post" action="/work-entries/%d/delete" onsubmit="return confirm(\'삭제하시겠습니까?\')">%s<input type="hidden" name="returnTo" value="%s"><button class="btn btn-sm btn-outline-danger" type="submit">삭제</button></form>
                        </div>
                    </div>
                </div>',
                $this->h((string)$entry['work_date']),
                $this->h(substr((string)$entry['start_at'], 11, 5)),
                $this->h(substr((string)$entry['end_at'], 11, 5)),
                $this->h($this->formatMinutes((int)$entry['work_minutes'])),
                $this->h((string)$entry['break_minutes']),
                $this->entryMemoBlock($entry['memo'] ?? null),
                $this->h((string)$entry['version']),
                (int)$entry['id'],
                rawurlencode($returnTo),
                (int)$entry['id'],
                CsrfService::input(),
                $this->h($returnTo)
            );
        }

        return $cards . '</div>';
    }

    private function entryMemoBlock(mixed $memo): string
    {
        if (!is_string($memo) || trim($memo) === '') {
            return '';
        }

        return sprintf('<div class="text-secondary small text-break">%s</div>', $this->h($memo));
    }

    private function workEntryFormFields(): string
    {
        return sprintf(
            '<div class="col-12 col-md-3"><label class="form-label" for="workDate">근무일</label><input class="form-control" id="workDate" type="date" name="workDate" value="%s" required></div>
            <div class="col-12 col-md-3">%s</div>
            <div class="col-12 col-md-3">%s</div>
            <div class="col-12 col-md-3"><label class="form-label" for="breakMinutes">휴게</label><input class="form-control" id="breakMinutes" type="number" name="breakMinutes" min="0" value="0" required></div>
            <div class="col-12"><label class="form-label" for="memo">메모</label><input class="form-control" id="memo" name="memo" maxlength="2000"></div>',
            $this->h(date('Y-m-d')),
            $this->timeSelectGroup('시작', 'start', '09', '00'),
            $this->timeSelectGroup('종료', 'end', '18', '00')
        );
    }

    private function timeSelectGroup(string $label, string $prefix, string $selectedHour, string $selectedMinute): string
    {
        return sprintf(
            '<div>
                <label class="form-label" for="%sHour">%s</label>
                <div class="d-flex gap-2">
                    <select class="form-select" id="%sHour" name="%sHour" aria-label="%s 시" required>%s</select>
                    <select class="form-select" id="%sMinute" name="%sMinute" aria-label="%s 분" required>%s</select>
                </div>
            </div>',
            $this->h($prefix),
            $this->h($label),
            $this->h($prefix),
            $this->h($prefix),
            $this->h($label),
            $this->hourOptions($selectedHour),
            $this->h($prefix),
            $this->h($prefix),
            $this->h($label),
            $this->minuteOptions($selectedMinute)
        );
    }

    private function hourOptions(string $selectedHour): string
    {
        $html = '';

        for ($hour = 0; $hour <= 23; $hour++) {
            $value = sprintf('%02d', $hour);
            $selected = $value === $selectedHour ? ' selected' : '';
            $html .= sprintf('<option value="%s"%s>%s시</option>', $value, $selected, $value);
        }

        return $html;
    }

    private function minuteOptions(string $selectedMinute): string
    {
        $html = '';

        for ($minute = 0; $minute <= 55; $minute += 5) {
            $value = sprintf('%02d', $minute);
            $selected = $value === $selectedMinute ? ' selected' : '';
            $html .= sprintf('<option value="%s"%s>%s분</option>', $value, $selected, $value);
        }

        return $html;
    }

    private function timeParts(string $dateTime): array
    {
        $time = substr($dateTime, 11, 5);

        return [
            substr($time, 0, 2),
            substr($time, 3, 2),
        ];
    }

    private function normalizedFiveMinute(string $minute): string
    {
        $value = (int)$minute;
        $value = (int)(floor($value / 5) * 5);

        return sprintf('%02d', $value);
    }

    private function timeFromPayload(array $payload, string $prefix, string $fallbackKey): string
    {
        $hour = $payload[$prefix . 'Hour'] ?? null;
        $minute = $payload[$prefix . 'Minute'] ?? null;

        if ($hour === null && $minute === null && isset($payload[$fallbackKey])) {
            return $this->stringInput($payload, $fallbackKey);
        }

        if (!is_string($hour) || !ctype_digit($hour) || (int)$hour < 0 || (int)$hour > 23) {
            throw new InvalidArgumentException(sprintf('%sHour 값이 올바르지 않습니다.', $prefix));
        }

        if (!is_string($minute) || !ctype_digit($minute) || (int)$minute < 0 || (int)$minute > 55 || (int)$minute % 5 !== 0) {
            throw new InvalidArgumentException(sprintf('%sMinute 값은 5분 단위여야 합니다.', $prefix));
        }

        return sprintf('%02d:%02d', (int)$hour, (int)$minute);
    }

    private function monthLink(string $type): string
    {
        $timestamp = $type === 'last' ? strtotime('first day of last month') : time();
        $from = date('Y-m-01', $timestamp);
        $to = date('Y-m-t', $timestamp);

        return '/work-entries/search?from=' . rawurlencode($from) . '&to=' . rawurlencode($to);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function editWorkEntryPage(array $entry, string $returnTo): string
    {
        $date = (string)$entry['work_date'];
        [$startHour, $startMinute] = $this->timeParts((string)$entry['start_at']);
        [$endHour, $endMinute] = $this->timeParts((string)$entry['end_at']);

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무 기록 수정</h1><p class="text-secondary mb-0">%s</p></div>
                <a class="btn btn-outline-secondary" href="%s">목록</a>
            </div>
            <form class="border rounded-2 bg-white p-3 row g-3" method="post" action="/work-entries/%d/edit">
                %s
                <input type="hidden" name="returnTo" value="%s">
                <div class="col-12 col-md-3"><label class="form-label" for="workDate">근무일</label><input class="form-control" id="workDate" type="date" name="workDate" value="%s" required></div>
                <div class="col-12 col-md-3">%s</div>
                <div class="col-12 col-md-3">%s</div>
                <div class="col-12 col-md-3"><label class="form-label" for="breakMinutes">휴게</label><input class="form-control" id="breakMinutes" type="number" name="breakMinutes" min="0" value="%s" required></div>
                <div class="col-12"><label class="form-label" for="memo">메모</label><input class="form-control" id="memo" name="memo" value="%s" maxlength="2000"></div>
                <div class="col-12"><button class="btn btn-primary" type="submit">수정</button></div>
            </form>',
            $this->h($date),
            $this->h($returnTo),
            (int)$entry['id'],
            CsrfService::input(),
            $this->h($returnTo),
            $this->h($date),
            $this->timeSelectGroup('시작', 'start', $startHour, $this->normalizedFiveMinute($startMinute)),
            $this->timeSelectGroup('종료', 'end', $endHour, $this->normalizedFiveMinute($endMinute)),
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
            'startAt' => $workDate . ' ' . $this->timeFromPayload($payload, 'start', 'startTime'),
            'endAt' => $workDate . ' ' . $this->timeFromPayload($payload, 'end', 'endTime'),
            'breakMinutes' => (int)($payload['breakMinutes'] ?? 0),
            'memo' => isset($payload['memo']) ? (string)$payload['memo'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function importEntriesFromPayload(array $payload): array
    {
        $rows = $payload['entries'] ?? null;

        if (!is_array($rows)) {
            throw new InvalidArgumentException('저장할 근무 기록이 없습니다.');
        }

        $entries = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entries[] = [
                'workDate' => $this->stringInput($row, 'workDate'),
                'startAt' => $this->stringInput($row, 'startAt'),
                'endAt' => $this->stringInput($row, 'endAt'),
                'breakMinutes' => isset($row['breakMinutes']) ? (int)$row['breakMinutes'] : 0,
                'memo' => isset($row['memo']) ? (string)$row['memo'] : null,
            ];
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array{0: string, 1: string}
     */
    private function entryRange(array $entries): array
    {
        $dates = array_map(fn (array $entry): string => (string)$entry['work_date'], $entries);
        sort($dates);

        return [$dates[0] ?? date('Y-m-01'), $dates[count($dates) - 1] ?? date('Y-m-d')];
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

    /**
     * @param array<string, mixed> $payload
     */
    private function returnPathFromPayload(array $payload, string $default): string
    {
        $returnTo = $payload['returnTo'] ?? null;

        if (!is_string($returnTo) || $returnTo === '' || !str_starts_with($returnTo, '/work-entries')) {
            return $default;
        }

        if (str_contains($returnTo, "\r") || str_contains($returnTo, "\n")) {
            return $default;
        }

        return $returnTo;
    }

    /**
     * @param array<string, mixed>|null $setting
     * @return array<string, mixed>|null
     */
    private function settingForAudit(?array $setting): ?array
    {
        if ($setting === null) {
            return null;
        }

        $setting['telegram_bot_token'] = $this->notificationSettingService->mask(
            isset($setting['telegram_bot_token']) ? (string)$setting['telegram_bot_token'] : null
        );
        $setting['telegram_chat_id'] = $this->notificationSettingService->mask(
            isset($setting['telegram_chat_id']) ? (string)$setting['telegram_chat_id'] : null
        );
        $setting['discord_webhook_url'] = $this->notificationSettingService->mask(
            isset($setting['discord_webhook_url']) ? (string)$setting['discord_webhook_url'] : null
        );

        return $setting;
    }

    /**
     * @return array<string, string|null>
     */
    private function requestContext(): array
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
        $this->securityHeaders();
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

    /**
     * @param array<string, mixed> $payload
     */
    private function validateCsrf(array $payload): bool
    {
        $token = $payload[CsrfService::FIELD_NAME] ?? null;

        return is_string($token) && CsrfService::validate($token);
    }

    private function securityHeaders(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
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

    private function minutesBetween(string $startAt, string $endAt): int
    {
        return (int)((strtotime($endAt) - strtotime($startAt)) / 60);
    }
}
