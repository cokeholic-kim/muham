<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Env;
use App\Database\HealthCheck;
use App\Middleware\AuthMiddleware;
use App\Services\AdminAiUserService;
use App\Services\AuditLogService;
use App\Services\CsrfService;
use App\Services\NotificationSettingService;
use App\Services\SessionService;
use App\Services\SupportInquiryService;
use App\Services\WebhookService;
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
        private readonly AdminAiUserService $adminAiUserService,
        private readonly NotificationSettingService $notificationSettingService,
        private readonly SupportInquiryService $supportInquiryService,
        private readonly AuditLogService $auditLogService,
        private readonly WebhookService $webhookService
    ) {
    }

    public function home(): never
    {
        $this->render(
            '머함 - 알바 근무시간 관리',
            $this->landingPage(),
            [
                'fullTitle' => '머함 - 알바 근무시간 관리',
                'description' => '머함은 알바·파트타임 근무시간을 기록하고 월별 내역을 정리하는 웹앱입니다.',
                'keywords' => '머함, 뭐함, 알바 근무시간, 파트타임 근무시간, 근무시간 관리, 근무 기록, 알바 시간표',
                'robots' => 'index,follow',
                'canonical' => '/',
            ]
        );
    }

    public function features(): never
    {
        $this->render(
            '기능',
            $this->featuresPage(),
            [
                'fullTitle' => '머함 기능 - 근무시간 입력과 월별 정리',
                'description' => '머함은 근무시간 입력, 월별 조회, 일괄 입력, 정기 발송으로 파트타임 근무 내역 관리를 돕습니다.',
                'keywords' => '머함 기능, 뭐함 기능, 알바 근무시간 관리, 근무시간 입력, 월별 근무 조회',
                'robots' => 'index,follow',
                'canonical' => '/features',
            ]
        );
    }

    public function guide(): never
    {
        $this->render(
            '사용 가이드',
            $this->guidePage(),
            [
                'fullTitle' => '머함 사용 가이드 - 알바 근무시간 기록 방법',
                'description' => '머함에서 알바 근무시간을 기록하고 월별 내역을 확인한 뒤 필요한 사람에게 전달하는 기본 사용 방법입니다.',
                'keywords' => '머함 사용법, 뭐함 사용법, 알바 근무시간 기록, 근무시간 정리 방법',
                'robots' => 'index,follow',
                'canonical' => '/guide',
            ]
        );
    }

    public function faq(): never
    {
        $this->render(
            '자주 묻는 질문',
            $this->faqPage(),
            [
                'fullTitle' => '머함 FAQ - 근무시간 관리 질문',
                'description' => '머함 근무시간 관리 웹앱의 사용 범위, AI 변환, 알림 발송, 데이터 관리에 대한 자주 묻는 질문입니다.',
                'keywords' => '머함 FAQ, 뭐함 FAQ, 근무시간 관리 질문, 알바 근무 기록',
                'robots' => 'index,follow',
                'canonical' => '/faq',
            ]
        );
    }

    public function privacy(): never
    {
        $this->render(
            '개인정보처리방침',
            $this->privacyPage(),
            [
                'fullTitle' => '머함 개인정보처리방침',
                'description' => '머함의 개인정보 수집, 이용, 보관, 보안 처리 기준을 안내합니다.',
                'keywords' => '머함 개인정보처리방침, 근무시간 관리 개인정보, 알바 근무 기록 개인정보',
                'robots' => 'index,follow',
                'canonical' => '/privacy',
            ]
        );
    }

    public function terms(): never
    {
        $this->render(
            '이용약관',
            $this->termsPage(),
            [
                'fullTitle' => '머함 이용약관',
                'description' => '머함 근무시간 관리 서비스 이용 조건과 사용자 책임을 안내합니다.',
                'keywords' => '머함 이용약관, 근무시간 관리 약관, 알바 근무 기록 서비스',
                'robots' => 'index,follow',
                'canonical' => '/terms',
            ]
        );
    }

    public function robotsTxt(): never
    {
        http_response_code(200);
        $this->securityHeaders();
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Disallow: /api/\n";
        echo "Disallow: /health\n";
        echo "Disallow: /health.json\n";
        echo "Disallow: /index.html\n";
        echo "Disallow: /notification-settings\n";
        echo "Disallow: /work-entries\n";
        echo 'Sitemap: ' . $this->absoluteUrl('/sitemap.xml') . "\n";
        exit;
    }

    public function sitemapXml(): never
    {
        $urls = ['/', '/features', '/guide', '/faq', '/privacy', '/terms'];
        $lastmod = date('Y-m-d');

        http_response_code(200);
        $this->securityHeaders();
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . $this->h($this->absoluteUrl($url)) . "</loc>\n";
            echo '    <lastmod>' . $lastmod . "</lastmod>\n";
            echo "  </url>\n";
        }

        echo "</urlset>\n";
        exit;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function loginForm(array $query): never
    {
        $returnTo = $this->appReturnPathFromPayload($query, '/work-entries');

        if (SessionService::userId() !== null) {
            $this->redirect($returnTo);
        }

        $signupHref = '/signup';

        if ($returnTo !== '/work-entries') {
            $signupHref .= '?returnTo=' . rawurlencode($returnTo);
        }

        $this->render('로그인', $this->authPage('로그인', '/login', '로그인', '계정이 없나요?', $signupHref, '회원가입', $returnTo));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function login(array $payload): never
    {
        $returnTo = $this->appReturnPathFromPayload($payload, '/work-entries');
        $loginPath = '/login';

        if ($returnTo !== '/work-entries') {
            $loginPath .= '?returnTo=' . rawurlencode($returnTo);
        }

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect($loginPath);
        }

        try {
            $this->authController->login($payload);
            $this->flash('success', '로그인되었습니다.');
            $this->redirect($returnTo);
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect($loginPath);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public function signupForm(array $query): never
    {
        $returnTo = $this->appReturnPathFromPayload($query, '/work-entries');

        if (SessionService::userId() !== null) {
            $this->redirect($returnTo);
        }

        $loginHref = '/login';

        if ($returnTo !== '/work-entries') {
            $loginHref .= '?returnTo=' . rawurlencode($returnTo);
        }

        $this->render('회원가입', $this->authPage('회원가입', '/signup', '회원가입', '이미 계정이 있나요?', $loginHref, '로그인', $returnTo));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function signup(array $payload): never
    {
        $returnTo = $this->appReturnPathFromPayload($payload, '/work-entries');
        $signupPath = '/signup';

        if ($returnTo !== '/work-entries') {
            $signupPath .= '?returnTo=' . rawurlencode($returnTo);
        }

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect($signupPath);
        }

        try {
            $this->authController->signup($payload);
            $this->flash('success', '회원가입이 완료되었습니다.');
            $this->redirect($returnTo);
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
            $this->redirect($signupPath);
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

    public function supportForm(): never
    {
        $user = $this->requireWebUser();

        try {
            $inquiries = $this->supportInquiryService->listForUser($user);
            $this->supportInquiryService->markAnsweredAsRead($user);
        } catch (Throwable $e) {
            $inquiries = [];
            $this->flash('danger', $e->getMessage());
        }

        $this->render('문의하기', $this->supportPage($inquiries));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $files
     */
    public function submitSupportInquiry(array $payload, array $files): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/support');
        }

        try {
            $result = $this->supportInquiryService->submit($user, $payload, $files);

            if ($result['discordSent']) {
                $this->flash('success', '문의가 접수되었습니다.');
            } else {
                $this->flash('warning', '문의 내용은 저장되었지만 Discord 알림 전송에 실패했습니다.');
            }
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect('/support');
    }

    /**
     * @param array<string, mixed> $query
     */
    public function adminSupportInquiries(array $query): never
    {
        $this->requireAdminUser();
        $status = $this->supportStatusFromPayload($query);

        try {
            $inquiries = $this->supportInquiryService->listForAdmin($status);
            $counts = $this->supportInquiryService->adminStatusCounts();
        } catch (Throwable $e) {
            $inquiries = [];
            $counts = ['all' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
            $this->flash('danger', $e->getMessage());
        }

        $this->render('문의 관리', $this->adminSupportInquiriesPage($inquiries, $status, $counts));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function answerSupportInquiry(int $inquiryId, array $payload): never
    {
        $admin = $this->requireAdminUser();
        $redirectPath = $this->adminSupportInquiriesPath($payload);

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect($redirectPath);
        }

        try {
            $change = $this->supportInquiryService->answer($admin, $inquiryId, $payload);
            $this->auditLogService->record(
                (int)$admin['id'],
                (int)$change['after']['user_id'],
                'answer_support_inquiry',
                'support_inquiry',
                $inquiryId,
                $this->supportInquiryForAudit($change['before']),
                $this->supportInquiryForAudit($change['after']),
                $this->requestContext()
            );
            $this->flash('success', '문의 답변을 저장했습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($redirectPath);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function closeSupportInquiry(int $inquiryId, array $payload): never
    {
        $admin = $this->requireAdminUser();
        $redirectPath = $this->adminSupportInquiriesPath($payload);

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect($redirectPath);
        }

        try {
            $change = $this->supportInquiryService->close($admin, $inquiryId);
            $this->auditLogService->record(
                (int)$admin['id'],
                (int)$change['after']['user_id'],
                'close_support_inquiry',
                'support_inquiry',
                $inquiryId,
                $this->supportInquiryForAudit($change['before']),
                $this->supportInquiryForAudit($change['after']),
                $this->requestContext()
            );
            $this->flash('success', '문의를 종료 처리했습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect($redirectPath);
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
        $this->render('근무시간 일괄 입력', $this->importWorkEntriesPage($this->workEntryImportService->aiStatus($user)));
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
        $this->requireWebUser();
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

    public function aiParserPrototype(): never
    {
        $this->requireWebUser();
        $path = dirname(__DIR__, 2) . '/index.html';

        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            $this->securityHeaders();
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            exit;
        }

        http_response_code(200);
        $this->securityHeaders();
        header('X-Robots-Tag: noindex,nofollow');
        header('Content-Type: text/html; charset=utf-8');
        readfile($path);
        exit;
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

    /**
     * @param array<string, mixed> $payload
     */
    public function sendNotificationNow(array $payload): never
    {
        $user = $this->requireWebUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/notification-settings');
        }

        try {
            $result = $this->webhookService->dispatchManualNotification($user);
            $type = match ($result['result']) {
                'success' => 'success',
                'rate_limited', 'skipped' => 'warning',
                default => 'danger',
            };
            $message = (string)$result['message'];

            if (isset($result['periodFrom'], $result['periodTo'])) {
                $message .= sprintf(' (%s ~ %s)', (string)$result['periodFrom'], (string)$result['periodTo']);
            }

            $this->flash($type, $message);
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect('/notification-settings');
    }

    public function adminAiUsers(): never
    {
        $this->requireAdminUser();

        try {
            $users = $this->adminAiUserService->listUsers();
            $logs = $this->adminAiUserService->recentUsageLogs();
        } catch (Throwable $e) {
            $users = [];
            $logs = [];
            $this->flash('danger', $e->getMessage());
        }

        $this->render('AI 사용자 관리', $this->adminAiUsersPage($users, $logs));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateAdminAiUser(int $userId, array $payload): never
    {
        $admin = $this->requireAdminUser();

        if (!$this->validateCsrf($payload)) {
            $this->flash('danger', '요청 보안 토큰이 올바르지 않습니다.');
            $this->redirect('/admin/ai-users');
        }

        try {
            $change = $this->adminAiUserService->updateUserAccess($userId, $payload);
            $this->auditLogService->record(
                (int)$admin['id'],
                $userId,
                'update_user_ai_access',
                'user',
                $userId,
                $this->userAiAccessForAudit($change['before']),
                $this->userAiAccessForAudit($change['after']),
                $this->requestContext()
            );
            $this->flash('success', 'AI 사용 권한을 저장했습니다.');
        } catch (Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }

        $this->redirect('/admin/ai-users');
    }

    private function authPage(
        string $title,
        string $action,
        string $button,
        string $altText,
        string $altHref,
        string $altLabel,
        string $returnTo = '/work-entries'
    ): string {
        $nameField = $action === '/signup'
            ? '<div class="mb-3"><label class="form-label" for="name">이름</label><input class="form-control" id="name" name="name" required maxlength="100"></div>'
            : '';
        $passwordAttributes = $action === '/signup'
            ? 'autocomplete="new-password" minlength="10"'
            : 'autocomplete="current-password"';
        $returnToField = $returnTo !== '/work-entries'
            ? sprintf('<input type="hidden" name="returnTo" value="%s">', $this->h($returnTo))
            : '';

        return sprintf(
            '<div class="row justify-content-center">
                <div class="col-12 col-sm-10 col-md-7 col-lg-5">
                    <h1 class="h3 mb-3">%s</h1>
                    <form class="border rounded-2 bg-white p-4" method="post" action="%s">
                        %s
                        %s
                        %s
                        <div class="mb-3"><label class="form-label" for="email">이메일</label><input class="form-control" id="email" type="email" name="email" required autocomplete="email"></div>
                        <div class="mb-3"><label class="form-label" for="password">비밀번호</label><input class="form-control" id="password" type="password" name="password" required %s></div>
                        <button class="btn btn-primary w-100" type="submit">%s</button>
                    </form>
                    <div class="mt-3 text-center text-secondary">%s <a href="%s">%s</a></div>
                </div>
            </div>',
            $this->h($title),
            $this->h($action),
            CsrfService::input(),
            $returnToField,
            $nameField,
            $passwordAttributes,
            $this->h($button),
            $this->h($altText),
            $this->h($altHref),
            $this->h($altLabel)
        );
    }

    private function landingPage(): string
    {
        $loggedIn = $this->hasSessionCookie() && SessionService::userId() !== null;
        $primaryHref = $loggedIn ? '/work-entries' : '/signup';
        $primaryLabel = $loggedIn ? '내 근무 기록 보기' : '무료로 시작하기';

        return sprintf(
            '<section class="py-4 py-md-5">
                <div class="row align-items-center g-4 g-lg-5">
                    <div class="col-12 col-lg-7">
                        <span class="badge text-bg-primary mb-3">머함 · 파트타임 근무시간 관리</span>
                        <h1 class="display-5 fw-semibold mb-3">머함에서 알바 근무시간을 기록하고 정리해서 바로 전달하세요</h1>
                        <p class="lead text-secondary mb-4">머함은 출근과 퇴근 시간, 휴게 시간, 월별 합계를 한 곳에서 관리하는 웹앱입니다. 뭐함처럼 쉽게 근무 내역을 입력하고 필요한 기간만 빠르게 확인할 수 있습니다.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary btn-lg" href="%s">%s</a>
                            <a class="btn btn-outline-secondary btn-lg" href="/guide">사용 방법</a>
                        </div>
                    </div>
                    <div class="col-12 col-lg-5">
                        <div class="border rounded-2 bg-white p-3 shadow-sm">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <img src="/pwa-icons/icon-192.png" width="56" height="56" alt="" class="rounded-2">
                                <div>
                                    <div class="fw-semibold">이번 달 근무 요약</div>
                                    <div class="text-secondary small">모바일에서도 바로 확인</div>
                                </div>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-4"><div class="border rounded-2 p-2"><div class="text-secondary small">근무일</div><div class="fs-5 fw-semibold">12일</div></div></div>
                                <div class="col-4"><div class="border rounded-2 p-2"><div class="text-secondary small">실근무</div><div class="fs-5 fw-semibold">58:30</div></div></div>
                                <div class="col-4"><div class="border rounded-2 p-2"><div class="text-secondary small">기록</div><div class="fs-5 fw-semibold">18건</div></div></div>
                            </div>
                            <div class="list-group list-group-flush border rounded-2">
                                <div class="list-group-item d-flex justify-content-between"><span>05/03 09:00 - 14:00</span><strong>5:00</strong></div>
                                <div class="list-group-item d-flex justify-content-between"><span>05/05 18:00 - 22:30</span><strong>4:30</strong></div>
                                <div class="list-group-item d-flex justify-content-between"><span>05/08 10:00 - 16:00</span><strong>5:30</strong></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <section class="py-4 border-top">
                <div class="row g-3">
                    %s
                </div>
            </section>',
            $this->h($primaryHref),
            $this->h($primaryLabel),
            $this->featureCards([
                ['빠른 입력', '날짜, 시작/종료 시간, 휴게 시간을 간단히 입력합니다.', $this->featureEntryPath('/work-entries/create')],
                ['월별 정리', '기간별 근무일, 실근무 시간, 휴게 시간을 자동 합산합니다.', $this->featureEntryPath('/work-entries/search')],
                ['일괄 변환', '여러 줄의 근무 메모를 한 번에 근무 기록으로 변환합니다.', $this->featureEntryPath('/work-entries/import')],
                ['정기 발송', '필요한 근무 요약을 텔레그램이나 디스코드로 전달합니다.', $this->featureEntryPath('/notification-settings')],
            ])
        );
    }

    private function featuresPage(): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">머함 기능</h1><p class="text-secondary mb-0">파트타임 근무 내역을 기록하고 정리하는 데 필요한 기본 기능입니다.</p></div>
                <a class="btn btn-primary" href="/signup">시작하기</a>
            </div>
            <section class="border-top border-bottom py-4 mb-4">
                <div class="row g-4">
                    <div class="col-12 col-md-4"><h2 class="h5">알바 근무시간 계산</h2><p class="text-secondary mb-0">출근, 퇴근, 휴게 시간을 입력하면 월별 실근무 시간을 확인할 수 있습니다.</p></div>
                    <div class="col-12 col-md-4"><h2 class="h5">파트타임 근무 기록</h2><p class="text-secondary mb-0">매장, 업무, 메모를 함께 남겨 나중에 급여 확인이나 근무 내역 전달에 활용할 수 있습니다.</p></div>
                    <div class="col-12 col-md-4"><h2 class="h5">월별 근무시간 정리</h2><p class="text-secondary mb-0">이번 달과 지난달 기록을 빠르게 비교하고 필요한 기간만 선택해 조회합니다.</p></div>
                </div>
            </section>
            <section class="row g-3">%s</section>',
            $this->featureCards([
                ['근무시간 입력', '근무일, 시작 시간, 종료 시간, 휴게 시간을 저장하고 나중에 수정할 수 있습니다.'],
                ['기간별 조회', '이번 달, 지난달, 직접 지정한 기간의 근무 기록과 합계를 확인합니다.'],
                ['AI 일괄 입력', '권한이 있는 사용자는 자유 형식 근무 메모를 서버 AI로 변환할 수 있습니다.'],
                ['알림 발송', '저장한 근무 요약을 수동 또는 정기 발송 흐름으로 전달할 수 있습니다.'],
                ['변경 이력', '근무 기록 수정과 삭제는 버전 및 감사 로그를 남기는 방향으로 관리합니다.'],
                ['PWA 지원', '모바일에서 설치해 앱처럼 사용할 수 있도록 manifest와 service worker를 제공합니다.'],
            ])
        );
    }

    private function guidePage(): string
    {
        return
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">머함 사용 가이드</h1><p class="text-secondary mb-0">근무시간을 입력하고 월별로 정리하는 기본 흐름입니다.</p></div>
                <a class="btn btn-primary" href="/signup">시작하기</a>
            </div>
            <section class="mb-4">
                <p class="text-secondary mb-0">머함은 알바 근무시간 계산과 파트타임 근무 기록을 간단하게 만들기 위한 도구입니다. 근무가 끝날 때마다 기록하거나, 여러 날의 메모를 한 번에 정리해 월별 근무시간을 확인할 수 있습니다.</p>
            </section>
            <section class="border rounded-2 bg-white p-3">
                <ol class="mb-0">
                    <li class="mb-3"><strong>계정을 만듭니다.</strong><div class="text-secondary">개인 근무 기록은 로그인한 사용자 기준으로 분리됩니다.</div></li>
                    <li class="mb-3"><strong>근무시간을 입력합니다.</strong><div class="text-secondary">근무일, 시작/종료 시간, 휴게 시간, 메모를 저장합니다.</div></li>
                    <li class="mb-3"><strong>기간별로 조회합니다.</strong><div class="text-secondary">이번 달 또는 원하는 기간의 실근무 시간을 확인합니다.</div></li>
                    <li><strong>필요한 방식으로 전달합니다.</strong><div class="text-secondary">정기 발송 설정을 사용하면 저장된 근무 요약을 메시지로 보낼 수 있습니다.</div></li>
                </ol>
            </section>';
    }

    private function faqPage(): string
    {
        return
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">머함 자주 묻는 질문</h1><p class="text-secondary mb-0">공개 서비스 사용 전에 확인할 수 있는 기본 안내입니다.</p></div>
                <a class="btn btn-primary" href="/signup">시작하기</a>
            </div>
            <section class="accordion" id="faqList">
                ' . $this->faqItem('faqOne', '누가 사용하면 좋나요?', '머함은 알바, 파트타임, 단기 근무처럼 매달 근무 시간이 달라지는 사람이 자신의 근무 내역을 정리할 때 적합합니다.', true) . '
                ' . $this->faqItem('faqTwo', 'AI 기능은 누구나 사용할 수 있나요?', '기본값은 사용 불가입니다. 관리자가 사용자별로 AI 사용 여부와 일일 한도를 설정한 경우에만 사용할 수 있습니다.', false) . '
                ' . $this->faqItem('faqThree', '브라우저에 API Key를 입력해야 하나요?', '아니요. 운영 기능은 서버에 설정된 API Key를 사용하고, 사용자별 권한과 횟수 제한을 적용합니다.', false) . '
                ' . $this->faqItem('faqFour', '근무 기록은 공개되나요?', '아니요. 근무 기록과 알림 설정은 로그인 후 본인 계정 기준으로 접근합니다.', false) . '
            </section>';
    }

    /**
     * @param array<int, array<string, mixed>> $inquiries
     */
    private function supportPage(array $inquiries): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">문의하기</h1><p class="text-secondary mb-0">오류, 개선 요청, 사용 중 불편한 점을 보내주세요.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3 mb-3">
                <form class="row g-3" method="post" action="/support" enctype="multipart/form-data">
                    %s
                    <div class="col-12">
                        <label class="form-label" for="subject">제목</label>
                        <input class="form-control" id="subject" name="subject" maxlength="120" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="message">내용</label>
                        <textarea class="form-control" id="message" name="message" rows="8" maxlength="5000" required></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="image">이미지 첨부</label>
                        <input class="form-control" id="image" type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
                        <div class="form-text">PNG, JPG, WEBP, GIF 이미지를 8MB 이하로 첨부할 수 있습니다. 이미지는 저장하지 않고 Discord 알림으로만 전송합니다. 일반 사용자는 기본적으로 1시간 3건, 하루 10건까지 문의할 수 있습니다.</div>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">문의 보내기</button></div>
                </form>
            </section>
            <section class="border rounded-2 bg-white">
                <div class="p-3 border-bottom">
                    <h2 class="h5 mb-1">내 문의 내역</h2>
                    <p class="text-secondary mb-0">답변이 등록되면 이 화면에서 확인할 수 있습니다.</p>
                </div>
                %s
            </section>',
            CsrfService::input(),
            $this->supportInquiryList($inquiries)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $inquiries
     */
    private function supportInquiryList(array $inquiries): string
    {
        if ($inquiries === []) {
            return '<div class="p-4 text-center text-secondary">아직 등록한 문의가 없습니다.</div>';
        }

        $items = '';

        foreach ($inquiries as $inquiry) {
            $newReplyBadge = (string)$inquiry['status'] === 'answered' && ($inquiry['user_read_at'] ?? null) === null
                ? '<span class="badge text-bg-primary ms-2">새 답변</span>'
                : '';
            $reply = isset($inquiry['admin_reply']) && trim((string)$inquiry['admin_reply']) !== ''
                ? '<div class="mt-3 p-3 rounded-2 bg-light"><div class="fw-semibold mb-2">답변' . $newReplyBadge . '</div><div class="text-break">' . nl2br($this->h((string)$inquiry['admin_reply'])) . '</div><div class="text-secondary small mt-2">' . $this->h((string)($inquiry['answered_at'] ?? '')) . '</div></div>'
                : '<div class="mt-3 p-3 rounded-2 bg-light text-secondary">아직 답변이 등록되지 않았습니다.</div>';

            $items .= sprintf(
                '<article class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                        <div>
                            <h3 class="h6 mb-1">%s</h3>
                            <div class="text-secondary small">%s · %s</div>
                        </div>
                        %s
                    </div>
                    <div class="mt-3 text-break">%s</div>
                    %s
                </article>',
                $this->h((string)$inquiry['subject']),
                $this->h('#' . (string)$inquiry['id']),
                $this->h((string)$inquiry['created_at']),
                $this->supportStatusBadge((string)$inquiry['status']),
                nl2br($this->h((string)$inquiry['message'])),
                $reply
            );
        }

        return $items;
    }

    private function supportStatusBadge(string $status): string
    {
        $label = match ($status) {
            'answered' => '답변 완료',
            'closed' => '종료',
            default => '접수',
        };
        $class = match ($status) {
            'answered' => 'success',
            'closed' => 'secondary',
            default => 'warning text-dark',
        };

        return sprintf('<span class="badge text-bg-%s">%s</span>', $class, $this->h($label));
    }

    private function privacyPage(): string
    {
        return
            '<div class="mb-4">
                <h1 class="h3 mb-1">개인정보처리방침</h1>
                <p class="text-secondary mb-0">머함은 근무시간 관리에 필요한 최소한의 정보를 사용합니다.</p>
            </div>
            <section class="border rounded-2 bg-white p-3">
                <h2 class="h5">수집하는 정보</h2>
                <p class="text-secondary">회원가입과 로그인에 필요한 이메일, 이름, 비밀번호 해시를 저장합니다. 사용자가 입력한 근무일, 시작/종료 시간, 휴게 시간, 메모, 알림 설정도 서비스 제공을 위해 저장됩니다.</p>
                <h2 class="h5 mt-4">이용 목적</h2>
                <p class="text-secondary">근무시간 입력, 조회, 월별 근무 내역 정리, 알림 발송, 보안 감사 로그 기록에 사용합니다.</p>
                <h2 class="h5 mt-4">보관과 보호</h2>
                <p class="text-secondary">비밀번호는 원문이 아닌 해시로 저장합니다. 알림 토큰 등 민감 정보는 서버 측에서 보호하며, 사용자의 근무 기록은 로그인한 본인 기준으로 접근을 제한합니다.</p>
                <h2 class="h5 mt-4">문의</h2>
                <p class="text-secondary mb-0">개인정보 관련 문의는 서비스 운영자에게 전달해 주세요.</p>
            </section>';
    }

    private function termsPage(): string
    {
        return
            '<div class="mb-4">
                <h1 class="h3 mb-1">이용약관</h1>
                <p class="text-secondary mb-0">머함 이용 전 확인해야 할 기본 조건입니다.</p>
            </div>
            <section class="border rounded-2 bg-white p-3">
                <h2 class="h5">서비스 목적</h2>
                <p class="text-secondary">머함은 사용자가 알바와 파트타임 근무시간을 기록하고 월별 내역을 정리할 수 있도록 돕는 웹앱입니다.</p>
                <h2 class="h5 mt-4">사용자 책임</h2>
                <p class="text-secondary">사용자는 본인의 근무 기록을 정확하게 입력하고, 계정 정보를 안전하게 관리해야 합니다. 급여 정산이나 공식 증빙 제출 전에는 실제 근무 조건과 사업장 기준을 함께 확인해야 합니다.</p>
                <h2 class="h5 mt-4">서비스 제한</h2>
                <p class="text-secondary">AI 변환, 알림 발송 등 일부 기능은 관리자 설정, 사용 한도, 외부 서비스 상태에 따라 제한될 수 있습니다.</p>
                <h2 class="h5 mt-4">변경</h2>
                <p class="text-secondary mb-0">운영상 필요한 경우 서비스 기능과 약관은 변경될 수 있습니다.</p>
            </section>';
    }

    /**
     * @param array<int, array{0: string, 1: string, 2?: string}> $items
     */
    private function featureCards(array $items): string
    {
        $html = '';

        foreach ($items as $item) {
            [$title, $description] = $item;
            $href = isset($item[2]) && is_string($item[2]) ? $item[2] : null;

            if ($href !== null) {
                $html .= sprintf(
                    '<div class="col-12 col-md-6 col-lg-3"><a class="border rounded-2 bg-white p-3 h-100 d-flex flex-column text-decoration-none text-body" href="%s"><div class="d-flex justify-content-between align-items-start gap-3 mb-2"><h2 class="h6 mb-0">%s</h2><span class="text-primary fw-semibold" aria-hidden="true">&gt;</span></div><p class="text-secondary mb-0">%s</p></a></div>',
                    $this->h($href),
                    $this->h($title),
                    $this->h($description)
                );
                continue;
            }

            $html .= sprintf(
                '<div class="col-12 col-md-6 col-lg-3"><div class="border rounded-2 bg-white p-3 h-100"><h2 class="h6 mb-2">%s</h2><p class="text-secondary mb-0">%s</p></div></div>',
                $this->h($title),
                $this->h($description)
            );
        }

        return $html;
    }

    private function featureEntryPath(string $targetPath): string
    {
        if ($this->hasSessionCookie() && SessionService::userId() !== null) {
            return $targetPath;
        }

        return '/login?returnTo=' . rawurlencode($targetPath);
    }

    private function faqItem(string $id, string $question, string $answer, bool $open): string
    {
        return sprintf(
            '<div class="accordion-item">
                <h2 class="accordion-header"><button class="accordion-button%s" type="button" data-bs-toggle="collapse" data-bs-target="#%s" aria-expanded="%s" aria-controls="%s">%s</button></h2>
                <div id="%s" class="accordion-collapse collapse%s" data-bs-parent="#faqList"><div class="accordion-body text-secondary">%s</div></div>
            </div>',
            $open ? '' : ' collapsed',
            $this->h($id),
            $open ? 'true' : 'false',
            $this->h($id),
            $this->h($question),
            $this->h($id),
            $open ? ' show' : '',
            $this->h($answer)
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $entries
     */
    private function workEntriesHomePage(array $user, array $entries): string
    {
        $adminAction = ($user['role'] ?? '') === 'admin'
            ? '<a class="btn btn-outline-primary" href="/admin/ai-users">AI 사용자 관리</a><a class="btn btn-outline-primary" href="/admin/support-inquiries">문의 관리</a>'
            : '';

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무 기록</h1><p class="text-secondary mb-0">%s · %s</p></div>
                <div class="d-flex gap-2 flex-wrap">%s<form method="post" action="/logout">%s<button class="btn btn-outline-secondary" type="submit">로그아웃</button></form></div>
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
            $adminAction,
            CsrfService::input(),
            $this->entriesTable($entries, '/work-entries')
        );
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @param array<int, array<string, mixed>> $logs
     */
    private function adminAiUsersPage(array $users, array $logs): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">AI 사용자 관리</h1><p class="text-secondary mb-0">사용자별 AI 변환 권한과 일일 사용 한도를 관리합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white mb-3">
                <div class="p-3 border-bottom">
                    <h2 class="h5 mb-1">사용자 권한</h2>
                    <p class="text-secondary mb-0">새 사용자는 기본적으로 AI 사용 불가, 일일 한도 0회입니다.</p>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>사용자</th><th>역할</th><th class="text-end">오늘</th><th class="text-end">전체</th><th>최근 사용</th><th class="text-end">권한</th></tr></thead>
                        <tbody>%s</tbody>
                    </table>
                </div>
            </section>
            <section class="border rounded-2 bg-white">
                <div class="p-3 border-bottom">
                    <h2 class="h5 mb-1">최근 AI 사용 로그</h2>
                    <p class="text-secondary mb-0">입력 원문은 저장하지 않고 SHA-256 해시와 결과만 기록합니다.</p>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>시간</th><th>사용자</th><th>모델</th><th>결과</th><th>오류</th></tr></thead>
                        <tbody>%s</tbody>
                    </table>
                </div>
            </section>',
            $this->adminAiUserRows($users),
            $this->adminAiUsageLogRows($logs)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $inquiries
     */
    /**
     * @param array{all: int, open: int, answered: int, closed: int} $counts
     */
    private function adminSupportInquiriesPage(array $inquiries, string $status, array $counts): string
    {
        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">문의 관리</h1><p class="text-secondary mb-0">사용자 문의를 확인하고 앱 내부 답변함으로 답변합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white">
                <div class="d-flex justify-content-between align-items-start gap-3 p-3 border-bottom flex-wrap">
                    <div>
                        <h2 class="h5 mb-1">최근 문의</h2>
                        <p class="text-secondary mb-0">이미지는 서버에 저장하지 않으며, Discord 전송용 메타데이터만 남습니다.</p>
                    </div>
                    %s
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th>문의</th><th>사용자</th><th>상태</th><th>내용</th><th>첨부</th><th>처리</th></tr></thead>
                        <tbody>%s</tbody>
                    </table>
                </div>
            </section>',
            $this->adminSupportFilterLinks($status, $counts),
            $this->adminSupportInquiryRows($inquiries, $status)
        );
    }

    /**
     * @param array{all: int, open: int, answered: int, closed: int} $counts
     */
    private function adminSupportFilterLinks(string $activeStatus, array $counts): string
    {
        $items = [
            'all' => '전체',
            'open' => '접수',
            'answered' => '답변 완료',
            'closed' => '종료',
        ];
        $html = '<div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="문의 상태 필터">';

        foreach ($items as $status => $label) {
            $class = $status === $activeStatus ? 'btn-primary' : 'btn-outline-primary';
            $href = $status === 'all' ? '/admin/support-inquiries' : '/admin/support-inquiries?status=' . rawurlencode($status);
            $html .= sprintf(
                '<a class="btn %s" href="%s">%s <span class="badge text-bg-light text-primary">%d</span></a>',
                $class,
                $this->h($href),
                $this->h($label),
                (int)($counts[$status] ?? 0)
            );
        }

        return $html . '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $inquiries
     */
    private function adminSupportInquiryRows(array $inquiries, string $activeStatus): string
    {
        if ($inquiries === []) {
            return '<tr><td class="text-center text-secondary py-4" colspan="6">문의가 없습니다.</td></tr>';
        }

        $rows = '';

        foreach ($inquiries as $inquiry) {
            $image = isset($inquiry['image_filename']) && $inquiry['image_filename'] !== null
                ? sprintf(
                    '<div>%s</div><div class="text-secondary small">%s</div>',
                    $this->h((string)$inquiry['image_filename']),
                    $this->h($this->formatBytes((int)($inquiry['image_size'] ?? 0)))
                )
                : '<span class="text-secondary">없음</span>';
            $discord = (int)($inquiry['discord_sent'] ?? 0) === 1
                ? '<div class="text-success small">Discord 전송됨</div>'
                : '<div class="text-warning small">Discord 미전송</div>';
            $discordError = isset($inquiry['discord_error']) && trim((string)$inquiry['discord_error']) !== ''
                ? '<div class="text-danger small text-break">' . $this->h((string)$inquiry['discord_error']) . '</div>'
                : '';
            $readState = isset($inquiry['user_read_at']) && $inquiry['user_read_at'] !== null
                ? '<div class="text-success small">사용자 확인: ' . $this->h((string)$inquiry['user_read_at']) . '</div>'
                : ((string)$inquiry['status'] === 'answered' ? '<div class="text-warning small">사용자 미확인</div>' : '');
            $closeForm = (string)$inquiry['status'] === 'closed'
                ? '<div class="text-secondary small">종료: ' . $this->h((string)($inquiry['closed_at'] ?? '')) . '</div>'
                : sprintf(
                    '<form class="mt-2" method="post" action="/admin/support-inquiries/%d/close" onsubmit="return confirm(\'문의를 종료 처리하시겠습니까?\')">%s<input type="hidden" name="status" value="%s"><button class="btn btn-sm btn-outline-secondary" type="submit">종료 처리</button></form>',
                    (int)$inquiry['id'],
                    CsrfService::input(),
                    $this->h($activeStatus)
                );

            $rows .= sprintf(
                '<tr>
                    <td><div class="fw-semibold">%s</div><div class="text-secondary small">#%d · %s</div></td>
                    <td><div>%s</div><div class="text-secondary small">%s</div></td>
                    <td>%s</td>
                    <td style="min-width:260px;white-space:normal"><div class="fw-semibold mb-1">%s</div><div class="text-break">%s</div></td>
                    <td>%s%s%s</td>
                    <td style="min-width:280px;white-space:normal">
                        <form method="post" action="/admin/support-inquiries/%d/answer">
                            %s
                            <label class="visually-hidden" for="adminReply%d">답변</label>
                            <textarea class="form-control form-control-sm mb-2" id="adminReply%d" name="adminReply" rows="4" maxlength="5000" required>%s</textarea>
                            <input type="hidden" name="status" value="%s">
                            <button class="btn btn-sm btn-primary" type="submit">%s</button>
                            %s
                        </form>
                        %s
                        %s
                    </td>
                </tr>',
                $this->h((string)$inquiry['subject']),
                (int)$inquiry['id'],
                $this->h((string)$inquiry['created_at']),
                $this->h((string)($inquiry['name'] ?? '')),
                $this->h((string)($inquiry['email'] ?? '')),
                $this->supportStatusBadge((string)$inquiry['status']),
                $this->h((string)$inquiry['subject']),
                nl2br($this->h((string)$inquiry['message'])),
                $image,
                $discord,
                $discordError,
                (int)$inquiry['id'],
                CsrfService::input(),
                (int)$inquiry['id'],
                (int)$inquiry['id'],
                $this->h((string)($inquiry['admin_reply'] ?? '')),
                $this->h($activeStatus),
                (string)$inquiry['status'] === 'answered' ? '답변 수정' : '답변 저장',
                isset($inquiry['answered_at']) && $inquiry['answered_at'] !== null
                    ? '<div class="text-secondary small mt-2">최근 답변: ' . $this->h((string)$inquiry['answered_at']) . '</div>'
                    : '',
                $readState,
                $closeForm
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     */
    private function adminAiUserRows(array $users): string
    {
        if ($users === []) {
            return '<tr><td class="text-center text-secondary py-4" colspan="6">사용자가 없습니다.</td></tr>';
        }

        $rows = '';

        foreach ($users as $user) {
            $enabled = (int)($user['ai_enabled'] ?? 0) === 1;
            $dailyLimit = (int)($user['ai_daily_limit'] ?? 0);
            $usedToday = (int)($user['ai_used_today'] ?? 0);
            $remaining = max(0, $dailyLimit - $usedToday);
            $lastUsedAt = isset($user['last_used_at']) && $user['last_used_at'] !== null
                ? (string)$user['last_used_at']
                : '-';

            $rows .= sprintf(
                '<tr>
                    <td><div class="fw-semibold">%s</div><div class="text-secondary small">%s</div></td>
                    <td>%s</td>
                    <td class="text-end">%d/%d회<div class="text-secondary small">남은 %d회</div></td>
                    <td class="text-end">%d회</td>
                    <td>%s</td>
                    <td>
                        <form class="d-flex justify-content-end align-items-center gap-2 flex-wrap" method="post" action="/admin/ai-users/%d">
                            %s
                            <input type="hidden" name="aiEnabled" value="0">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" id="aiEnabled%d" type="checkbox" name="aiEnabled" value="1"%s>
                                <label class="form-check-label small" for="aiEnabled%d">허용</label>
                            </div>
                            <label class="visually-hidden" for="aiDailyLimit%d">일일 한도</label>
                            <input class="form-control form-control-sm text-end" id="aiDailyLimit%d" type="number" min="0" max="10000" name="aiDailyLimit" value="%d" style="width: 104px">
                            <button class="btn btn-sm btn-primary" type="submit">저장</button>
                        </form>
                    </td>
                </tr>',
                $this->h((string)$user['name']),
                $this->h((string)$user['email']),
                $this->h((string)$user['role']),
                $usedToday,
                $dailyLimit,
                $remaining,
                (int)($user['ai_total_usage_count'] ?? 0),
                $this->h($lastUsedAt),
                (int)$user['id'],
                CsrfService::input(),
                (int)$user['id'],
                $enabled ? ' checked' : '',
                (int)$user['id'],
                (int)$user['id'],
                (int)$user['id'],
                $dailyLimit
            );
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     */
    private function adminAiUsageLogRows(array $logs): string
    {
        if ($logs === []) {
            return '<tr><td class="text-center text-secondary py-4" colspan="5">AI 사용 로그가 없습니다.</td></tr>';
        }

        $rows = '';

        foreach ($logs as $log) {
            $errorMessage = isset($log['error_message']) && is_string($log['error_message']) && trim($log['error_message']) !== ''
                ? $log['error_message']
                : '-';

            $rows .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td><div class="fw-semibold">%s</div><div class="text-secondary small">%s</div></td>
                    <td>%s<div class="text-secondary small">%s</div></td>
                    <td>%s</td>
                    <td class="text-break">%s</td>
                </tr>',
                $this->h((string)$log['created_at']),
                $this->h((string)$log['name']),
                $this->h((string)$log['email']),
                $this->h((string)$log['provider']),
                $this->h((string)$log['model']),
                $this->aiUsageResultBadge((string)$log['result']),
                $this->h($errorMessage)
            );
        }

        return $rows;
    }

    private function aiUsageResultBadge(string $result): string
    {
        $class = match ($result) {
            'success' => 'success',
            'failed' => 'danger',
            'rate_limited' => 'warning text-dark',
            'disabled' => 'secondary',
            default => 'info text-dark',
        };

        return sprintf('<span class="badge text-bg-%s">%s</span>', $class, $this->h($result));
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
     * @param array<string, mixed> $aiStatus
     */
    private function importWorkEntriesPage(array $aiStatus): string
    {
        $aiUsable = (bool)($aiStatus['usable'] ?? false);
        $aiHelp = $this->aiUsageHelp($aiStatus);
        $sample = "04/04 5:30 - 16:00\n04/05 14:00 - 16:30\n\n04/13 9:30 - 10:00 , 14:15 - 16:15";

        return sprintf(
            '<div class="d-flex justify-content-between align-items-start gap-3 mb-4 flex-wrap">
                <div><h1 class="h3 mb-1">근무시간 일괄 입력</h1><p class="text-secondary mb-0">정규 포맷은 빠르게 해석하고, 권한이 있는 사용자는 서버 AI로 애매한 텍스트를 변환합니다.</p></div>
                <a class="btn btn-outline-secondary" href="/work-entries">홈</a>
            </div>
            <section class="border rounded-2 bg-white p-3">
                <form class="row g-3" method="post" action="/work-entries/import/preview">
                    %s
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="baseYear">기준 연도</label>
                        <input class="form-control" id="baseYear" name="baseYear" inputmode="numeric" value="%s" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="parserMode">변환 방식</label>
                        <select class="form-select" id="parserMode" name="parserMode">
                            <option value="auto">정규 포맷 우선</option>
                            <option value="pattern">정규 포맷만</option>
                            <option value="ai"%s>AI로 변환</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">AI 사용 상태</label>
                        <div class="form-control bg-light">%s</div>
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
            $aiUsable ? '' : ' disabled',
            $this->h($aiHelp),
            $this->h($sample)
        );
    }

    /**
     * @param array<string, mixed> $aiStatus
     */
    private function aiUsageHelp(array $aiStatus): string
    {
        if (!($aiStatus['configured'] ?? false)) {
            return '서버 AI Key 미설정';
        }

        if (!($aiStatus['enabled'] ?? false)) {
            return 'AI 사용 불가';
        }

        $dailyLimit = (int)($aiStatus['dailyLimit'] ?? 0);
        $usedToday = (int)($aiStatus['usedToday'] ?? 0);
        $provider = (string)($aiStatus['provider'] ?? '');
        $model = (string)($aiStatus['model'] ?? '');

        if ($dailyLimit < 1) {
            return 'AI 일일 한도 0회';
        }

        return sprintf('%s / %s · 오늘 %d/%d회 사용', $provider, $model, $usedToday, $dailyLimit);
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
            <section class="border rounded-2 bg-white p-3 mb-3">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                    <div>
                        <h2 class="h5 mb-1">수동 발송</h2>
                        <p class="text-secondary mb-0">저장된 정기 발송 설정과 요약 기간 기준으로 지금 바로 메시지를 보냅니다. 사용자별 1시간 3회까지 가능합니다.</p>
                    </div>
                    <form method="post" action="/notification-settings/send">
                        %s
                        <button class="btn btn-primary" type="submit">지금 발송</button>
                    </form>
                </div>
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
            CsrfService::input(),
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
            $loginPath = '/login';
            $currentPath = $this->currentPath();

            if ($this->isSafeAppReturnPath($currentPath)) {
                $loginPath .= '?returnTo=' . rawurlencode($currentPath);
            }

            $this->flash('danger', '로그인이 필요합니다.');
            $this->redirect($loginPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireAdminUser(): array
    {
        try {
            return $this->authMiddleware->requireRole('admin');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === '접근 권한이 없습니다.') {
                $this->flash('danger', '관리자 권한이 필요합니다.');
                $this->redirect('/work-entries');
            }

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
     * @param array<string, mixed> $payload
     */
    private function appReturnPathFromPayload(array $payload, string $default): string
    {
        $returnTo = $payload['returnTo'] ?? null;

        if (!is_string($returnTo) || !$this->isSafeAppReturnPath($returnTo)) {
            return $default;
        }

        return $returnTo;
    }

    private function isSafeAppReturnPath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return false;
        }

        if (str_contains($path, "\r") || str_contains($path, "\n")) {
            return false;
        }

        $allowed = [
            '/work-entries',
            '/notification-settings',
            '/support',
        ];

        foreach ($allowed as $prefix) {
            if (
                $path === $prefix ||
                str_starts_with($path, $prefix . '/') ||
                str_starts_with($path, $prefix . '?')
            ) {
                return true;
            }
        }

        return false;
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
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userAiAccessForAudit(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'ai_enabled' => (int)($user['ai_enabled'] ?? 0),
            'ai_daily_limit' => (int)($user['ai_daily_limit'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $inquiry
     * @return array<string, mixed>
     */
    private function supportInquiryForAudit(array $inquiry): array
    {
        return [
            'id' => (int)$inquiry['id'],
            'user_id' => (int)$inquiry['user_id'],
            'status' => (string)$inquiry['status'],
            'has_reply' => isset($inquiry['admin_reply']) && trim((string)$inquiry['admin_reply']) !== '',
            'answered_by' => $inquiry['answered_by'] ?? null,
            'answered_at' => $inquiry['answered_at'] ?? null,
            'user_read_at' => $inquiry['user_read_at'] ?? null,
            'closed_by' => $inquiry['closed_by'] ?? null,
            'closed_at' => $inquiry['closed_at'] ?? null,
        ];
    }

    private function supportUnreadAnswerCount(): int
    {
        $userId = SessionService::userId();

        if ($userId === null) {
            return 0;
        }

        try {
            return $this->supportInquiryService->unreadAnswerCount(['id' => $userId]);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function supportStatusFromPayload(array $payload): string
    {
        $status = $payload['status'] ?? 'all';

        return is_string($status) ? $this->supportInquiryService->statusFilter($status) : 'all';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function adminSupportInquiriesPath(array $payload): string
    {
        $status = $this->supportStatusFromPayload($payload);

        return $status === 'all' ? '/admin/support-inquiries' : '/admin/support-inquiries?status=' . rawurlencode($status);
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

    /**
     * @param array<string, string> $meta
     */
    private function render(string $title, string $body, array $meta = []): never
    {
        $hasSession = session_status() === PHP_SESSION_ACTIVE || $this->hasSessionCookie();
        $flash = null;

        if ($hasSession) {
            SessionService::start();
            $flash = $_SESSION['flash'] ?? null;
            unset($_SESSION['flash']);
        }

        $flashHtml = '';

        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            $flashHtml = sprintf(
                '<div class="alert alert-%s" role="alert">%s</div>',
                $this->h((string)$flash['type']),
                $this->h((string)$flash['message'])
            );
        }

        $fullTitle = ($meta['fullTitle'] ?? ($title . ' · 근무시간 관리'));
        $description = $meta['description'] ?? '근무시간 입력, 조회, 기간별 정리를 위한 웹앱입니다.';
        $keywords = $meta['keywords'] ?? '';
        $robots = $meta['robots'] ?? 'noindex,nofollow';
        $canonical = $this->absoluteUrl($meta['canonical'] ?? $this->currentPath());
        $ogImage = $this->absoluteUrl('/pwa-icons/og-image.png');
        $isLoggedIn = $hasSession && SessionService::userId() !== null;
        $supportUnreadCount = $isLoggedIn ? $this->supportUnreadAnswerCount() : 0;
        $supportBadge = $supportUnreadCount > 0
            ? sprintf(' <span class="badge text-bg-danger">%d</span>', $supportUnreadCount)
            : '';
        $navAction = $isLoggedIn
            ? '<a class="btn btn-sm btn-primary" href="/work-entries">내 기록</a>'
            : '<a class="btn btn-sm btn-primary" href="/login">로그인</a>';
        $internalLinks = $isLoggedIn
            ? '<a class="btn btn-sm btn-outline-secondary" href="/support">문의' . $supportBadge . '</a><a class="btn btn-sm btn-outline-secondary" href="/health">상태</a><a class="btn btn-sm btn-outline-secondary" href="/index.html">AI 파서</a>'
            : '';
        $publicLinks = '<a class="btn btn-sm btn-outline-secondary" href="/features">기능</a><a class="btn btn-sm btn-outline-secondary" href="/guide">가이드</a><a class="btn btn-sm btn-outline-secondary" href="/faq">FAQ</a>';

        http_response_code(200);
        $this->securityHeaders();
        if (str_contains($robots, 'noindex')) {
            header('X-Robots-Tag: ' . $robots);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . $this->h($fullTitle) . '</title>';
        echo '<meta name="description" content="' . $this->h($description) . '">';
        if ($keywords !== '') {
            echo '<meta name="keywords" content="' . $this->h($keywords) . '">';
        }
        echo '<meta name="robots" content="' . $this->h($robots) . '">';
        echo '<link rel="canonical" href="' . $this->h($canonical) . '">';
        echo '<meta property="og:type" content="website">';
        echo '<meta property="og:locale" content="ko_KR">';
        echo '<meta property="og:site_name" content="머함">';
        echo '<meta property="og:title" content="' . $this->h($fullTitle) . '">';
        echo '<meta property="og:description" content="' . $this->h($description) . '">';
        echo '<meta property="og:url" content="' . $this->h($canonical) . '">';
        echo '<meta property="og:image" content="' . $this->h($ogImage) . '">';
        echo '<meta property="og:image:width" content="1731">';
        echo '<meta property="og:image:height" content="909">';
        echo '<meta name="twitter:card" content="summary">';
        echo '<meta name="twitter:title" content="' . $this->h($fullTitle) . '">';
        echo '<meta name="twitter:description" content="' . $this->h($description) . '">';
        echo '<meta name="twitter:image" content="' . $this->h($ogImage) . '">';
        echo '<link rel="icon" type="image/png" sizes="192x192" href="/pwa-icons/icon-192.png">';
        echo '<link rel="apple-touch-icon" href="/pwa-icons/icon-192.png">';
        echo '<link rel="manifest" href="/manifest.json"><meta name="theme-color" content="#05285f">';
        echo $this->structuredData($fullTitle, $description, $canonical);
        echo $this->googleAnalyticsSnippet($title);
        echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
        echo '<style>body{background:#f6f7f9}.navbar{border-bottom:1px solid #dee2e6}.container-narrow{max-width:1120px}.table th,.table td{white-space:nowrap}.table td:nth-child(6){white-space:normal;min-width:160px}.display-5{letter-spacing:0}.lead{line-height:1.65}.site-footer{border-top:1px solid #dee2e6;background:#fff}@media(max-width:575.98px){.container-narrow{padding-left:14px;padding-right:14px}.table th,.table td{font-size:.875rem}}</style>';
        echo '</head><body><nav class="navbar bg-white"><div class="container container-narrow"><a class="navbar-brand fw-semibold" href="/">머함</a><div class="d-flex gap-2 flex-wrap">' . $publicLinks . $internalLinks . $navAction . '</div></div></nav>';
        echo '<main class="container container-narrow py-4">' . $flashHtml . $body . '</main>';
        echo $this->footerHtml();
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>';
        echo '<script>if ("serviceWorker" in navigator) { window.addEventListener("load", function () { navigator.serviceWorker.register("/sw.js?v=2"); }); }</script>';
        echo '</body></html>';
        exit;
    }

    private function googleAnalyticsSnippet(string $title): string
    {
        if (Env::get('APP_ENV') !== 'production') {
            return '';
        }

        $measurementId = trim(Env::get('GA_MEASUREMENT_ID'));

        if ($measurementId === '' || preg_match('/^G-[A-Z0-9]+$/', $measurementId) !== 1) {
            return '';
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';

        if ($path === '/health') {
            return '';
        }

        $encodedId = rawurlencode($measurementId);
        $pagePathJson = json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $pageTitleJson = json_encode($title . ' · 근무시간 관리', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($pagePathJson === false || $pageTitleJson === false) {
            return '';
        }

        return sprintf(
            '<script async src="https://www.googletagmanager.com/gtag/js?id=%s"></script>
            <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag("js", new Date());
            gtag("config", "%s", {"send_page_view": false});
            gtag("event", "page_view", {"page_path": %s, "page_title": %s});
            </script>',
            $encodedId,
            $this->h($measurementId),
            $pagePathJson,
            $pageTitleJson
        );
    }

    private function structuredData(string $title, string $description, string $url): string
    {
        $payload = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => '머함',
            'alternateName' => ['뭐함', '근무시간 관리'],
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => $url,
            'description' => $description,
            'inLanguage' => 'ko-KR',
            'offers' => [
                '@type' => 'Offer',
                'price' => '0',
                'priceCurrency' => 'KRW',
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return '';
        }

        return '<script type="application/ld+json">' . $json . '</script>';
    }

    private function footerHtml(): string
    {
        return '<footer class="site-footer py-4 mt-4">
            <div class="container container-narrow d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <div class="text-secondary small">머함 · 알바·파트타임 근무시간 관리</div>
                <nav class="d-flex gap-3 flex-wrap small" aria-label="하단 링크">
                    <a class="link-secondary text-decoration-none" href="/features">기능</a>
                    <a class="link-secondary text-decoration-none" href="/guide">가이드</a>
                    <a class="link-secondary text-decoration-none" href="/faq">FAQ</a>
                    <a class="link-secondary text-decoration-none" href="/support">문의하기</a>
                    <a class="link-secondary text-decoration-none" href="/privacy">개인정보처리방침</a>
                    <a class="link-secondary text-decoration-none" href="/terms">이용약관</a>
                </nav>
            </div>
        </footer>';
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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://www.googletagmanager.com https://www.google-analytics.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self' https://www.google-analytics.com https://region1.google-analytics.com https://generativelanguage.googleapis.com https://api.openai.com https://api.anthropic.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

        if (Env::get('APP_ENV') === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
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

    private function hasSessionCookie(): bool
    {
        $sessionId = $_COOKIE['muham_session'] ?? null;

        return is_string($sessionId) && $sessionId !== '';
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = rtrim(Env::get('APP_URL', 'http://localhost:8000'), '/');

        if ($path === '') {
            $path = '/';
        }

        if (preg_match('#^https?://#', $path) === 1) {
            return $path;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    private function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1fMB', $bytes / 1024 / 1024);
        }

        return sprintf('%.1fKB', max(1, $bytes) / 1024);
    }

    private function minutesBetween(string $startAt, string $endAt): int
    {
        return (int)((strtotime($endAt) - strtotime($startAt)) / 60);
    }
}
