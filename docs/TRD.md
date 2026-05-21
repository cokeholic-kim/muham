# TRD: 근무시간 관리 시스템 기술 구현 문서

## 1. 기술 스택

- Language: PHP 8.4
- Database: MySQL 8.0
- DB 연결 및 쿼리: PDO
- 환경 설정: `.env`
- 프로젝트 시작점: `index.php`
- 로컬 실행: Docker Compose
- UI: Bootstrap 5 계열

## 2. 기술 결정사항

| 항목 | 결정 |
| --- | --- |
| 인증 방식 | PHP session 기반 로그인 |
| 세션 쿠키 | HTTP-only cookie 사용 |
| 권한 | `user`, `admin` 두 역할로 시작 |
| 근무 기록 수정 | 원본 보존, `version` 증가, 감사 로그 기록 |
| 근무 기록 삭제 | 물리 삭제 금지, soft delete 처리 |
| 웹훅 보안 | IP allowlist, shared secret, `requestId` 중복 방지 |
| 텔레그램 대상 | 사용자별 `telegram_chat_id` 우선, 없으면 기본 chat id 사용 |
| 시간대 | `Asia/Seoul` 기준 |
| DB 마이그레이션 | `migrations/*.sql` 파일로 관리 |
| DB 접근 | PDO prepared statement만 사용 |

## 3. 로컬 실행 환경

Docker Compose로 PHP 8.4와 MySQL 8.0을 함께 실행합니다.

```bash
docker compose up -d --build
```

접속:

```text
http://localhost:8000
```

기존 AI 근무시간 파서:

```text
http://localhost:8000/index.html
```

중지:

```bash
docker compose down
```

MySQL 데이터까지 초기화:

```bash
docker compose down -v
```

## 4. 현재 파일 구조

```text
.
├── Dockerfile
├── docker-compose.yml
├── docker/
│   └── apache/
│       └── 000-default.conf
├── .htaccess
├── app/
│   ├── Config/
│   ├── Controllers/
│   ├── Database/
│   ├── Middleware/
│   ├── Models/
│   └── Services/
├── migrations/
│   ├── 001_create_users.sql
│   ├── 002_create_work_entries.sql
│   ├── 003_create_audit_logs.sql
│   └── 004_create_webhook_events.sql
├── public/
├── logs/
├── docs/
│   ├── PRD.md
│   ├── TRD.md
│   ├── IMPLEMENTATION_PLAN.md
│   ├── DOCKER_GUIDE.md
│   └── DEPLOY_GUIDE.md
├── .env.example
├── index.php
├── index.html
├── app.js
├── styles.css
└── README.md
```

## 5. 목표 애플리케이션 구조

```text
.
├── index.php
├── app/
│   ├── Config/
│   ├── Controllers/
│   ├── Database/
│   ├── Middleware/
│   ├── Models/
│   └── Services/
├── public/
│   ├── app.js
│   └── styles.css
├── migrations/
├── logs/
├── docs/
├── .env.example
└── README.md
```

`index.php`는 front controller로 동작합니다. 모든 요청은 `index.php`에서 라우팅하고, 각 기능은 인증, 근무시간, 웹훅, 감사 로그 모듈로 분리합니다.

현재 서버 렌더링 화면:

- `/`: 로그인 상태에 따라 `/login` 또는 `/work-entries`로 이동
- `/login`: 로그인 화면
- `/signup`: 회원가입 화면
- `/logout`: 로그아웃 POST
- `/work-entries`: 최근 근무 기록 10건과 입력/조회 진입점
- `/work-entries/create`: 근무시간 입력 화면
- `/work-entries/import`: 정규 포맷 또는 AI 변환 기반 근무시간 일괄 입력 화면
- `/work-entries/search`: 기간 조회, 요약, 목록 화면
- `/work-entries/:id/edit`: 근무 기록 수정 화면
- `/work-entries/:id/delete`: 근무 기록 soft delete POST
- `/notification-settings`: 정기 발송 설정 화면
- `/health`: 개발용 환경 점검 화면
- `/index.html`: 기존 AI 근무시간 파서

## 6. 환경변수

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_PORT=8000
APP_TIMEZONE=Asia/Seoul

DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=muham_worktime
DB_USERNAME=muham
DB_PASSWORD=change-this-db-password
DB_CHARSET=utf8mb4

MYSQL_PORT=3307
MYSQL_ROOT_PASSWORD=change-this-root-password

SESSION_SECRET=change-this-local-secret
WEBHOOK_SHARED_SECRET=change-this-webhook-secret
WEBHOOK_ALLOWED_IPS=127.0.0.1
WEBHOOK_ACTIVE_FROM=2026-01-01
WEBHOOK_ACTIVE_TO=2026-12-31
TELEGRAM_BOT_TOKEN=
TELEGRAM_DEFAULT_CHAT_ID=
```

원칙:

- DB 접속 정보, 텔레그램 토큰, 웹훅 secret 등 민감 정보는 소스코드에 직접 작성하지 않습니다.
- `.env`는 git에 커밋하지 않고, 배포 환경별로 별도 관리합니다.
- `.env.example`에는 로컬 개발용 예시 값만 둡니다.

## 7. DB 접근 원칙

- 모든 DB 연결은 PDO로만 처리합니다.
- 모든 쿼리는 prepared statement를 사용합니다.
- PDO는 예외 모드(`PDO::ERRMODE_EXCEPTION`)로 설정합니다.
- 기본 fetch mode는 associative array로 설정합니다.
- 트랜잭션이 필요한 작업은 근무 기록 변경과 감사 로그 기록을 하나의 트랜잭션으로 처리합니다.

현재 구현:

- `App\Database\Database::connection()`에서 `.env` 기반 PDO 연결을 생성합니다.
- DB 접속 환경변수는 코드 기본값으로 대체하지 않고 필수값으로 검증합니다.
- `App\Database\Database::statement()`로 prepared statement를 실행합니다.
- `App\Database\Database::fetchOne()`, `fetchAll()`로 조회 쿼리 실행을 공통화합니다.
- `App\Database\Database::transaction()`으로 콜백 기반 트랜잭션을 실행합니다.
- 개발용 health check는 `App\Database\HealthCheck`에서 공통 DB 계층을 사용합니다.

## 8. 인증 구현

- 로그인 상태는 PHP session으로 관리합니다.
- 세션 쿠키는 HTTP-only로 설정합니다.
- 운영 환경에서는 secure cookie를 사용하고 HTTPS를 강제합니다.
- 로그인 성공, 실패, 로그아웃은 모두 `audit_logs`에 기록합니다.
- 로그인 실패가 반복되면 계정 또는 IP 기준으로 요청을 제한합니다.

권한:

- `user`: 본인의 근무 기록 입력, 조회, 정정 요청 또는 수정 가능
- `admin`: 전체 사용자 근무 기록 조회, 감사 로그 조회, 웹훅 처리 내역 조회 가능

모든 조회와 변경 API는 현재 로그인 사용자와 대상 데이터의 권한을 검증해야 합니다.

현재 구현:

- `App\Services\AuthService`에서 회원가입, 로그인, 사용자 조회를 처리합니다.
- `App\Services\SessionService`에서 세션 쿠키 설정, 로그인 세션 저장, 로그아웃을 처리합니다.
- 세션 쿠키는 `HttpOnly`, `SameSite=Lax`를 사용하며, 운영 환경 또는 HTTPS 요청에서는 `Secure`를 사용합니다.
- `App\Middleware\AuthMiddleware`에서 로그인 사용자 확인과 역할 체크를 처리합니다.
- 공개 회원가입은 기본 `user` 역할만 생성합니다.
- `POST /api/auth/signup`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/me`가 구현되어 있습니다.
- 감사 로그 기록과 로그인 실패 제한은 후속 보안 작업에서 연결합니다.

## 9. 데이터 모델

### users

| 필드 | 설명 |
| --- | --- |
| id | 사용자 고유 ID |
| email | 로그인 이메일 |
| password_hash | 비밀번호 해시 |
| name | 사용자 이름 |
| role | `user`, `admin` |
| telegram_chat_id | 사용자별 텔레그램 수신처 |
| created_at | 가입 시각 |
| updated_at | 수정 시각 |

### work_entries

| 필드 | 설명 |
| --- | --- |
| id | 근무 기록 ID |
| user_id | 사용자 ID |
| work_date | 근무일 |
| start_at | 시작 시각 |
| end_at | 종료 시각 |
| break_minutes | 휴게 시간 |
| work_minutes | 실제 근무 시간 |
| memo | 메모 |
| status | `active`, `corrected`, `deleted` |
| version | 변경 버전 |
| created_by | 생성자 |
| updated_by | 마지막 수정자 |
| deleted_at | soft delete 시각 |
| created_at | 생성 시각 |
| updated_at | 수정 시각 |

### audit_logs

| 필드 | 설명 |
| --- | --- |
| id | 로그 ID |
| actor_user_id | 행위자 ID |
| target_user_id | 대상 사용자 ID |
| action | `signup`, `login`, `create_work`, `update_work`, `delete_work`, `webhook_summary` 등 |
| entity_type | 대상 데이터 종류 |
| entity_id | 대상 데이터 ID |
| before_json | 변경 전 값 |
| after_json | 변경 후 값 |
| request_ip | 요청 IP |
| user_agent | user-agent |
| request_id | 요청 추적 ID |
| prev_hash | 이전 감사 로그 해시 |
| hash | 현재 감사 로그 해시 |
| created_at | 기록 시각 |

현재 구현:

- `App\Services\AuditLogService`에서 감사 로그 기록을 담당합니다.
- `record()`는 단독 감사 로그를 기록하고, `recordInTransaction()`은 다른 데이터 변경과 같은 트랜잭션 안에서 감사 로그를 기록합니다.
- 새 감사 로그는 직전 감사 로그의 `hash`를 `prev_hash`로 저장하고, 정규화한 로그 데이터로 SHA-256 `hash`를 생성합니다.
- `signup`, `login`, `login_failed`, `logout` 이벤트는 인증 API에서 감사 로그로 기록합니다.
- 요청의 `X-Request-Id`가 있으면 사용하고, 없으면 서버에서 요청 ID를 생성해 `request_id`에 저장합니다.

### webhook_events

| 필드 | 설명 |
| --- | --- |
| id | 웹훅 이벤트 ID |
| request_id | 중복 방지용 요청 ID |
| source_ip | 요청 IP |
| allowed | 허용 여부 |
| period_from | 정리 시작일 |
| period_to | 정리 종료일 |
| payload_json | 요청 본문 |
| result | `success`, `rejected`, `failed` |
| error_message | 실패 사유 |
| created_at | 요청 시각 |

### webhook_request_logs

| 필드 | 설명 |
| --- | --- |
| id | 웹훅 수신 로그 ID |
| request_id | payload의 요청 ID. 없으면 NULL |
| path | 요청 경로 |
| method | 요청 method |
| source_ip | 요청 IP |
| headers_json | 민감 헤더를 제외한 요청 헤더 |
| body_sha256 | 요청 본문 SHA-256 |
| raw_body | 요청 본문 원문. 추적용으로 저장 |
| payload_json | JSON 파싱이 성공한 payload |
| parse_status | `parsed`, `empty`, `invalid_json` |
| error_message | 파싱 오류 메시지 |
| created_at | 수신 시각 |

### notification_settings

| 필드 | 설명 |
| --- | --- |
| id | 정기 발송 설정 ID |
| user_id | 설정 소유 사용자 ID |
| channel | `telegram`, `discord` |
| telegram_bot_token | Telegram Bot token |
| telegram_chat_id | Telegram Chat ID |
| discord_webhook_url | Discord Webhook URL |
| summary_period_type | 요약 기간 기준. `previous_month`, `current_month`, `previous_7_days`, `custom` |
| custom_period_from | 직접 지정 시작일. `summary_period_type=custom`일 때 사용 |
| custom_period_to | 직접 지정 종료일. `summary_period_type=custom`일 때 사용 |
| monthly_send_day | 매월 발송일 |
| is_active | 활성 여부 |
| created_at | 생성 시각 |
| updated_at | 수정 시각 |

### user_ai_settings

| 필드 | 설명 |
| --- | --- |
| id | AI 설정 ID |
| user_id | 설정 소유 사용자 ID |
| provider | `gemini`, `openai`, `anthropic` |
| model | 사용할 AI 모델 |
| api_key_ciphertext | 암호화된 API Key |
| api_key_hint | 화면 표시용 마스킹 힌트 |
| created_at | 생성 시각 |
| updated_at | 수정 시각 |

## 10. API 설계

### 인증

- `POST /api/auth/signup` 회원가입
- `POST /api/auth/login` 로그인
- `POST /api/auth/logout` 로그아웃
- `GET /api/me` 내 정보 조회

### 근무시간

- `POST /api/work-entries` 근무시간 등록
- `GET /api/work-entries?from=YYYY-MM-DD&to=YYYY-MM-DD` 기간별 조회
- `GET /api/work-entries/summary?from=YYYY-MM-DD&to=YYYY-MM-DD` 기간별 합계 조회
- `PATCH /api/work-entries/:id` 근무시간 수정
- `DELETE /api/work-entries/:id` 근무시간 삭제 요청 또는 비활성화

현재 구현:

- `App\Services\WorkEntryService`에서 근무 기록 생성, 조회, 요약, 수정, soft delete를 처리합니다.
- `App\Controllers\WorkEntryController`에서 근무 기록 API 요청/응답을 처리합니다.
- 일반 사용자는 본인 기록만 접근할 수 있고, 관리자는 `userId` 파라미터로 대상 사용자를 지정할 수 있습니다.
- 근무 시간은 `startAt`, `endAt`, `breakMinutes`를 기준으로 검증하고 `work_minutes`를 계산합니다.
- 동일 사용자의 겹치는 `active` 근무 시간대는 등록할 수 없습니다.
- 수정 시 `version`을 증가시키고, 삭제는 `status=deleted`, `deleted_at`을 기록하는 soft delete로 처리합니다.
- 생성/수정/삭제는 `AuditLogService::recordInTransaction()`으로 감사 로그와 같은 트랜잭션에서 처리합니다.
- `/work-entries/import` 화면에서 정규 포맷 또는 AI 변환 결과를 미리보기한 뒤 `WorkEntryService::bulkCreate()`로 일괄 저장합니다.
- 사용자 AI API Key는 `user_ai_settings`에 암호화해 저장하고, 화면에는 마스킹 힌트만 표시합니다.

### 웹훅

- `POST /api/webhooks/work-summary` 근무시간 요약 발송

현재 구현:

- `App\Controllers\WebhookController`에서 `POST /api/webhooks/work-summary` 요청을 처리합니다.
- `App\Services\WebhookRequestLogService`에서 웹훅 수신 요청을 `webhook_request_logs`에 먼저 기록합니다.
- JSON 파싱 실패 요청도 `webhook_request_logs.parse_status=invalid_json`으로 남깁니다.
- `App\Services\WebhookService`에서 IP allowlist, 유효 기간, shared secret, `requestId` 중복 방지, 기간별 근무 요약 생성을 처리합니다.
- `userId/from/to` payload는 기존 단건 Telegram 발송 경로로 처리합니다.
- `triggerDate/requestId` payload는 정기 발송 실행 신호로 처리하고, 해당 일자에 발송해야 하는 활성 설정을 조회합니다.
- 정기 발송 요약 기간은 설정의 `summary_period_type` 기준으로 실행 시점에 계산합니다.
- `App\Services\TelegramService`에서 Telegram Bot API `sendMessage`를 호출합니다.
- `App\Services\DiscordService`에서 Discord Webhook URL로 메시지를 전송합니다.
- `App\Services\NotificationSettingService`에서 사용자별 정기 발송 설정을 저장하고 조회합니다.
- shared secret은 `X-Webhook-Secret` 헤더 또는 `Authorization: Bearer ...`로 전달합니다.
- 웹훅 수신 로그는 `webhook_request_logs`, 처리 결과는 `webhook_events`에 `success`, `rejected`, `failed`로 저장합니다.
- 웹훅 요약, 정기 발송, 거부 결과는 `audit_logs`에 `webhook_summary`, `webhook_scheduled_summary`, `webhook_rejected`로 기록합니다.
- 텔레그램 토큰 또는 chat id가 없으면 요약은 생성하고 발송 실패/스킵 상태를 기록합니다.

## 11. 웹훅 처리 흐름

1. 요청 IP가 허용 목록에 있는지 확인합니다.
2. 요청 시간이 허용 기간 안인지 확인합니다.
3. shared secret을 검증합니다.
4. `requestId`로 중복 요청 여부를 확인합니다.
5. 단건 payload이면 대상 사용자의 지정 기간 근무 기록을 조회합니다.
6. 정기 실행 payload이면 `triggerDate`의 일(day)과 `monthly_send_day`가 같은 활성 설정을 조회합니다.
7. 각 설정의 요약 기간 기준으로 조회 기간을 계산합니다.
8. 계산된 기간의 근무 기록을 조회합니다.
9. 총 근무시간, 휴게시간, 실제 근무시간을 계산합니다.
10. 설정된 채널에 따라 Telegram 또는 Discord로 메시지를 전송합니다.
11. 성공 또는 실패 결과를 `webhook_events`와 `audit_logs`에 기록합니다.

## 12. 시간대 정책

- 초기 구현은 PHP, MySQL, 화면 표시 모두 `Asia/Seoul` 기준으로 통일합니다.
- `.env`의 `APP_TIMEZONE=Asia/Seoul`을 기준값으로 사용합니다.
- 모든 날짜 필터는 `YYYY-MM-DD` 형식을 사용합니다.
- `created_at`, `updated_at`, 감사 로그 시각은 서버 기준 현재 시각으로 저장합니다.

## 13. 마이그레이션 정책

- DB 스키마는 `migrations/*.sql` 파일로 관리합니다.
- 파일명은 실행 순서가 보장되도록 숫자 prefix를 사용합니다.
- 예: `001_create_users.sql`, `002_create_work_entries.sql`
- 운영 DB에 적용한 마이그레이션은 수정하지 않고 새 파일로 변경합니다.
- 추후 필요하면 `schema_migrations` 테이블을 추가해 적용 여부를 관리합니다.

## 14. 보안 체크리스트

- [x] 비밀번호 원문 저장 금지
- [x] 사용자별 데이터 접근 권한 검증
- [x] 관리자 권한 분리
- [x] 근무 기록 변경 이력 저장
- [x] 감사 로그 애플리케이션 append-only 정책 적용
- [x] 감사 로그 해시 체인 기록
- [x] 웹훅 IP 제한
- [x] 웹훅 shared secret 검증
- [x] 웹훅 중복 요청 방지
- [x] 텔레그램 토큰 환경변수 관리
- [x] 서버 렌더링 폼 CSRF 보호
- [x] 기본 보안 응답 헤더 적용
- [ ] 운영 환경 HTTPS 적용
- [ ] 애플리케이션 내부 rate limit 구현
- [ ] 데이터베이스 정기 백업 자동화

운영 보안과 백업 정책은 [SECURITY_OPERATIONS.md](SECURITY_OPERATIONS.md)에서 관리합니다.

## 15. Bootstrap 적용 예시

서버 렌더링 PHP 화면에서는 HTML에 Bootstrap class를 직접 적용합니다.

```html
<form class="row g-3">
  <div class="col-md-4">
    <label for="work-date" class="form-label">근무일</label>
    <input type="date" id="work-date" name="work_date" class="form-control" required>
  </div>
  <div class="col-md-4">
    <label for="start-at" class="form-label">시작 시간</label>
    <input type="time" id="start-at" name="start_at" class="form-control" required>
  </div>
  <div class="col-md-4">
    <label for="end-at" class="form-label">종료 시간</label>
    <input type="time" id="end-at" name="end_at" class="form-control" required>
  </div>
  <div class="col-12">
    <button type="submit" class="btn btn-primary">저장</button>
  </div>
</form>
```
