# Implementation Plan: 근무시간 관리 시스템

이 문서는 [PRD.md](PRD.md)와 [TRD.md](TRD.md)를 기준으로 구현 작업을 추적합니다.

Docker 설정과 로컬 실행 방법은 [DOCKER_GUIDE.md](DOCKER_GUIDE.md)에서 관리합니다.

작업을 완료할 때마다 각 태스크의 체크박스를 갱신하고, `작업 결과`, `검증 결과`, `메모`를 채웁니다.

## 검증 원칙

- 로컬 웹 화면이나 라우팅을 변경한 작업은 Docker 실행 상태에서 Playwright로 브라우저 접속 검증을 수행합니다.
- 최소 확인 경로는 `/`, 새로 추가한 경로, 기존 `/index.html`, 보안 차단 경로입니다.
- 보안 차단 경로는 `403` 또는 `404`가 정상입니다.
- `curl`은 보조 검증으로 사용하고, 최종 화면 동작은 Playwright 결과를 함께 기록합니다.

## 상태 규칙

- `[ ]`: 시작 전
- `[~]`: 진행 중
- `[x]`: 완료

## 전체 진행률

- [x] Task 1. 애플리케이션 기본 구조와 front controller 정리
- [x] Task 2. MySQL 마이그레이션 SQL 작성
- [x] Task 3. PDO 데이터베이스 계층과 트랜잭션 유틸 구현
- [x] Task 4. 세션 인증과 user/admin 권한 구현
- [x] Task 5. 감사 로그 서비스와 변경 무결성 기록 구현
- [x] Task 6. 근무 기록 CRUD와 기간별 조회/요약 API 구현
- [x] Task 7. Bootstrap 기반 서버 렌더링 UI와 기존 AI 파서 정리
- [x] Task 8. 웹훅 보안 검증과 텔레그램 발송 구현
- [x] Task 9. 보안 hardening과 통합 검증
- [x] Task 10. 근무 기록 홈, 입력, 조회 화면 분리
- [x] Task 11. 정기 발송 설정 UI와 실행 신호 웹훅 구현
- [x] Task 12. 근무시간 일괄 입력과 웹훅 수신 로그 보강

## Task 1. 애플리케이션 기본 구조와 front controller 정리

상태: [x]

### 목표

TRD의 목표 구조에 맞춰 PHP 애플리케이션 디렉터리와 공통 부트스트랩 흐름을 만든다. `index.php`는 환경 점검 화면에서 front controller 역할로 전환할 준비를 하고, 기존 `.env` 로딩과 PDO 점검 코드는 `app/Config` 및 `app/Database`로 이동 가능한 구조로 정리한다.

### 작업 범위

- `app/Config`, `app/Database`, `app/Controllers`, `app/Middleware`, `app/Models`, `app/Services` 생성
- `migrations`, `public`, `logs` 생성
- `index.php`에 최소 라우팅 분기 구조 추가
- 기존 `/index.html` AI 파서 접근 유지
- 개발용 health/check 화면 또는 경로 유지

### 관련 파일

- `index.php`
- `app/Config/`
- `app/Database/`
- `docs/TRD.md`

### 완료 기준

- `docker compose` 환경에서 `http://localhost:8000` 접속이 유지된다.
- 새 애플리케이션 디렉터리 구조가 생성된다.
- `index.php`가 요청 경로별 분기 구조를 가진다.
- 기존 `http://localhost:8000/index.html` 접근이 깨지지 않는다.

### 작업 결과

- `app/Config`, `app/Database`, `app/Controllers`, `app/Middleware`, `app/Models`, `app/Services` 디렉터리를 생성했다.
- `migrations`, `public`, `logs` 디렉터리를 생성했다.
- 기존 `index.php`의 `.env` 로딩 로직을 `App\Config\Env`로 분리했다.
- 기존 DB 연결 점검 로직을 `App\Database\HealthCheck`로 분리했다.
- `index.php`를 최소 front controller 형태로 정리하고 `/`, `/health`, `/health.json` 경로를 처리하도록 구성했다.
- 파일/디렉터리가 아닌 요청을 `index.php`로 보내도록 `.htaccess`에 rewrite 규칙을 추가했다.
- 기존 `/index.html` AI 근무시간 파서 접근은 유지했다.

### 검증 결과

- `docker compose ps`에서 `muham_app`, `muham_mysql` 컨테이너가 실행 중이고 MySQL healthcheck가 `healthy`임을 확인했다.
- `docker compose exec app php -l index.php` 성공.
- `docker compose exec app php -l app/Config/Env.php` 성공.
- `docker compose exec app php -l app/Database/HealthCheck.php` 성공.
- `curl http://127.0.0.1:8000/health.json` 응답에서 `status: ok`, `pdo_mysql_loaded: yes`, `mysql_version: 8.0.44` 확인.
- `curl -I http://127.0.0.1:8000/` 응답 `200 OK` 확인.
- `curl -I http://127.0.0.1:8000/index.html` 응답 `200 OK` 확인.
- `curl -I http://127.0.0.1:8000/.env` 응답 `403 Forbidden` 확인.
- Playwright로 `http://127.0.0.1:8000/`에 접속해 페이지 제목 `근무시간 관리 시스템`, `Database: OK`, `PDO MySQL connection is ready.` 표시를 확인했다.
- Playwright 브라우저 컨텍스트에서 `/`, `/health.json`, `/index.html`, `/.env`, `/favicon.ico`를 확인했다.
- Playwright 경로별 상태: `/` 200, `/health.json` 200, `/index.html` 200, `/.env` 403, `/favicon.ico` 204.

### 메모

- Task 3에서 현재 `HealthCheck` 내부의 직접 PDO 생성 로직을 정식 DB connection provider로 한 번 더 정리할 예정이다.

## Task 2. MySQL 마이그레이션 SQL 작성

상태: [x]

### 목표

TRD 데이터 모델에 따라 `users`, `work_entries`, `audit_logs`, `webhook_events` 테이블을 생성하는 `migrations/*.sql` 파일을 작성한다.

### 작업 범위

- `migrations/001_create_users.sql`
- `migrations/002_create_work_entries.sql`
- `migrations/003_create_audit_logs.sql`
- `migrations/004_create_webhook_events.sql`
- `user_id`, `work_date`, `status`, `created_at`, `request_id` 등 조회 기준 인덱스 추가
- `webhook_events.request_id` unique 제약 추가
- MySQL 8.0, InnoDB, utf8mb4 기준 작성

### 관련 파일

- `migrations/001_create_users.sql`
- `migrations/002_create_work_entries.sql`
- `migrations/003_create_audit_logs.sql`
- `migrations/004_create_webhook_events.sql`
- `docs/TRD.md`

### 완료 기준

- MySQL 8.0 컨테이너에서 모든 SQL 파일이 순서대로 실행된다.
- 네 개의 핵심 테이블이 생성된다.
- `users.role`, `users.telegram_chat_id`, `work_entries.version`, `work_entries.deleted_at`, `audit_logs.prev_hash`, `audit_logs.hash`, `webhook_events.request_id`가 확인된다.

### 작업 결과

- `migrations/001_create_users.sql` 작성.
- `migrations/002_create_work_entries.sql` 작성.
- `migrations/003_create_audit_logs.sql` 작성.
- `migrations/004_create_webhook_events.sql` 작성.
- `users.role`, `users.telegram_chat_id`를 반영했다.
- `work_entries.version`, `work_entries.deleted_at`, `status`, 사용자/일자/상태 기준 인덱스를 반영했다.
- `audit_logs.before_json`, `audit_logs.after_json`, `audit_logs.prev_hash`, `audit_logs.hash`, 요청 메타데이터 필드를 반영했다.
- `webhook_events.request_id` unique 제약과 처리 결과/기간/요청 IP 인덱스를 반영했다.

### 검증 결과

- `docker compose cp migrations mysql:/tmp/migrations`로 SQL 파일을 MySQL 컨테이너에 복사했다.
- MySQL 8.0 컨테이너에서 `001`부터 `004`까지 순서대로 적용했다.
- `SHOW TABLES` 결과 `users`, `work_entries`, `audit_logs`, `webhook_events` 네 개 테이블 생성을 확인했다.
- `SHOW COLUMNS`로 `users.role`, `users.telegram_chat_id`, `work_entries.version`, `work_entries.deleted_at`, `audit_logs.prev_hash`, `audit_logs.hash`, `webhook_events.request_id` 존재를 확인했다.
- `SHOW INDEX FROM webhook_events WHERE Key_name = 'uq_webhook_events_request_id'`로 `request_id` unique 인덱스를 확인했다.

### 메모

- 현재 마이그레이션은 `CREATE TABLE IF NOT EXISTS` 기반이다. 추후 스키마 변경은 새 SQL 파일로 추가한다.

## Task 3. PDO 데이터베이스 계층과 트랜잭션 유틸 구현

상태: [x]

### 목표

`.env` 기반 DB 접속 정보를 사용해 PDO 연결 클래스를 구현하고, prepared statement 사용을 강제하는 기본 query 유틸과 트랜잭션 실행 유틸을 만든다.

### 작업 범위

- PDO connection provider 구현
- `PDO::ERRMODE_EXCEPTION` 설정
- `PDO::FETCH_ASSOC` 설정
- `PDO::ATTR_EMULATE_PREPARES = false` 설정
- prepared statement 실행용 메서드 정의
- transaction callback 유틸 정의
- 개발용 DB health check 경로에서 연결 확인

### 관련 파일

- `app/Database/`
- `app/Config/`
- `index.php`
- `.env.example`

### 완료 기준

- 컨테이너 내부 PHP에서 `pdo_mysql` 확장이 로드된다.
- 앱의 DB health 경로가 MySQL 버전과 현재 DB명을 반환한다.
- 테스트 쿼리는 prepared statement 경로로만 실행된다.

### 작업 결과

- `app/Database/Database.php`를 추가해 `.env` 기반 PDO connection provider를 구현했다.
- DB 접속에 필요한 `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`은 기본값 없이 필수 환경변수로 읽도록 구성했다.
- PDO 옵션으로 `PDO::ERRMODE_EXCEPTION`, `PDO::FETCH_ASSOC`, `PDO::ATTR_EMULATE_PREPARES = false`를 설정했다.
- `Database::statement()`로 prepared statement 실행 경로를 공통화했다.
- `Database::fetchOne()`, `Database::fetchAll()` 조회 유틸을 추가했다.
- `Database::transaction()` 콜백 유틸을 추가해 트랜잭션 시작, commit, rollback 처리를 공통화했다.
- `HealthCheck`의 직접 PDO 생성과 직접 query 호출을 제거하고 `Database::fetchOne()`을 사용하도록 변경했다.
- `index.php`에서 공통 DB 계층을 로드하도록 `require_once`를 추가했다.

### 검증 결과

- `docker compose exec app php -l app/Database/Database.php` 성공.
- `docker compose exec app php -l app/Database/HealthCheck.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- `curl http://localhost:8000/health.json` 응답에서 `status: ok`, `database_name: muham_worktime`, `mysql_version: 8.0.44` 확인.
- 컨테이너 내부 PHP에서 `Database::transaction()`과 `Database::fetchOne("SELECT ? AS marker", ["task3"])` 실행 결과 `marker: task3` 확인.
- 컨테이너 내부 PHP에서 PDO 옵션 확인: `ERRMODE=2`, `FETCH_MODE=2`, `EMULATE_PREPARES=false`.
- `curl -I http://localhost:8000/` 응답 `200 OK` 확인.
- `curl -I http://localhost:8000/index.html` 응답 `200 OK` 확인.
- `curl -I http://localhost:8000/.env` 응답 `403 Forbidden` 확인.
- Playwright로 `http://localhost:8000/` 접속 시 페이지 제목 `근무시간 관리 시스템`, `Database: OK`, `PDO MySQL connection is ready.` 표시를 확인했다.
- Playwright로 `/health.json`, `/index.html`, `/.env` 경로 접속을 확인했다.

### 메모

- 이후 Task 4부터는 인증/회원가입 쿼리를 모두 `Database` 유틸과 트랜잭션 경로로 구현한다.

## Task 4. 세션 인증과 user/admin 권한 구현

상태: [x]

### 목표

PHP session 기반 회원가입, 로그인, 로그아웃, 내 정보 조회를 구현한다. 비밀번호는 `password_hash`, `password_verify` 기반으로 처리하고, 세션 쿠키는 HTTP-only로 설정한다.

### 작업 범위

- `AuthController` 구현
- `AuthService` 구현
- `POST /api/auth/signup`
- `POST /api/auth/login`
- `POST /api/auth/logout`
- `GET /api/me`
- 로그인 사용자 확인 미들웨어
- `user`, `admin` 권한 체크 유틸 또는 미들웨어

### 관련 파일

- `app/Controllers/`
- `app/Services/`
- `app/Middleware/`
- `index.php`
- `migrations/001_create_users.sql`

### 완료 기준

- 회원가입 후 로그인할 수 있다.
- 세션 기반으로 `GET /api/me`가 현재 사용자를 반환한다.
- 잘못된 비밀번호 로그인은 실패한다.
- `user`, `admin` 권한 구분이 가능하다.
- 세션 쿠키에 HTTP-only 속성이 적용된다.

### 작업 결과

- `app/Services/AuthService.php`를 추가해 회원가입, 로그인, 사용자 조회 로직을 구현했다.
- `app/Services/SessionService.php`를 추가해 PHP session 시작, 로그인 세션 저장, 로그아웃, 세션 사용자 조회를 공통화했다.
- 세션 쿠키는 `HttpOnly`, `SameSite=Lax`로 설정하고, 운영 환경 또는 HTTPS 요청에서는 `Secure`가 적용되도록 구성했다.
- `app/Middleware/AuthMiddleware.php`를 추가해 로그인 사용자 확인과 `user/admin` 역할 체크를 구현했다.
- `app/Controllers/AuthController.php`를 추가해 인증 API 요청/응답 처리를 분리했다.
- `POST /api/auth/signup`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/me` 라우팅을 추가했다.
- 공개 회원가입은 기본 `user` 권한만 생성하도록 제한했다.
- 비밀번호는 `password_hash()`로 저장하고 로그인 시 `password_verify()`로 검증하도록 구현했다.

### 검증 결과

- `docker compose exec app php -l index.php` 성공.
- `docker compose exec app php -l app/Services/AuthService.php` 성공.
- `docker compose exec app php -l app/Services/SessionService.php` 성공.
- `docker compose exec app php -l app/Controllers/AuthController.php` 성공.
- `docker compose exec app php -l app/Middleware/AuthMiddleware.php` 성공.
- 미로그인 상태의 `GET /api/me` 응답 `401 Unauthorized` 확인.
- `POST /api/auth/signup`으로 회원가입 성공 및 `Set-Cookie: muham_session=...; HttpOnly; SameSite=Lax` 확인.
- 회원가입 후 세션 쿠키로 `GET /api/me` 호출 시 현재 사용자 정보와 `role: user` 반환 확인.
- 잘못된 비밀번호로 `POST /api/auth/login` 호출 시 `401 Unauthorized` 확인.
- `POST /api/auth/logout` 호출 시 세션 쿠키 삭제와 로그아웃 응답 확인.
- 로그아웃 후 `GET /api/me` 응답 `401 Unauthorized` 확인.
- MySQL에서 가입 사용자의 `password_hash`가 `$2y$...` 형식이고 평문 비밀번호와 같지 않음을 확인했다.
- `AuthMiddleware::requireRole("admin")`이 `user` 계정을 `접근 권한이 없습니다.`로 거부하는 것을 확인했다.
- Playwright로 `/`, `/health.json`, `/api/me`, `/.env` 경로 접속을 확인했다.

### 메모

- 로그인 성공, 실패, 로그아웃 감사 로그 기록은 Task 5의 감사 로그 서비스 구현 시 연결한다.

## Task 5. 감사 로그 서비스와 변경 무결성 기록 구현

상태: [x]

### 목표

로그인, 근무 기록 생성/수정/삭제, 웹훅 처리 등 주요 이벤트를 `audit_logs`에 기록하는 서비스를 구현한다.

### 작업 범위

- `AuditLogService` 구현
- `before_json`, `after_json` 저장
- `request_ip`, `user_agent`, `request_id` 저장
- 마지막 `audit_logs.hash` 조회 후 `prev_hash`로 연결
- 현재 로그 `hash` 생성
- 근무 기록 변경과 감사 로그 기록을 같은 트랜잭션에서 처리할 수 있는 인터페이스 구성

### 관련 파일

- `app/Services/`
- `app/Database/`
- `migrations/003_create_audit_logs.sql`

### 완료 기준

- 로그인 성공/실패 또는 테스트 이벤트가 `audit_logs`에 기록된다.
- 연속 로그의 `prev_hash`가 이전 `hash`와 연결된다.
- `before_json`, `after_json`, `request_ip`, `user_agent`가 저장된다.

### 작업 결과

- `app/Services/AuditLogService.php`를 추가해 감사 로그 기록 서비스를 구현했다.
- `AuditLogService::record()`로 단독 감사 로그 기록을 처리한다.
- `AuditLogService::recordInTransaction()`으로 다른 데이터 변경과 같은 트랜잭션 안에서 감사 로그를 기록할 수 있는 인터페이스를 제공한다.
- 마지막 감사 로그의 `hash`를 조회해 새 로그의 `prev_hash`로 저장하고, 정규화한 로그 데이터로 SHA-256 `hash`를 생성하도록 구현했다.
- `before_json`, `after_json`, `request_ip`, `user_agent`, `request_id` 저장을 구현했다.
- `AuthController`에 감사 로그 서비스를 연결해 `signup`, `login`, `login_failed`, `logout` 이벤트를 기록하도록 구현했다.
- 요청의 `X-Request-Id`가 있으면 감사 로그 `request_id`로 사용하고, 없으면 서버에서 요청 ID를 생성하도록 구현했다.

### 검증 결과

- `docker compose exec app php -l app/Services/AuditLogService.php` 성공.
- `docker compose exec app php -l app/Controllers/AuthController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- `audit_logs` 테이블 존재와 초기 로그 수를 확인했다.
- `POST /api/auth/signup` 호출 후 `signup` 감사 로그 생성 확인.
- 잘못된 비밀번호로 `POST /api/auth/login` 호출 후 `login_failed` 감사 로그 생성 확인.
- 정상 `POST /api/auth/login` 호출 후 `login` 감사 로그 생성 확인.
- `POST /api/auth/logout` 호출 후 `logout` 감사 로그 생성 확인.
- MySQL에서 `request_ip`, `request_id`, `hash` 64자 저장을 확인했다.
- 연속 로그의 `prev_hash`가 직전 로그의 `hash`와 연결되는 것을 확인했다.
- 앱 DB 계층으로 `before_json`, `after_json`, `user_agent` 저장 여부를 확인했다.
- `curl -I http://localhost:8000/` 응답 `200 OK` 확인.
- `curl http://localhost:8000/api/me` 미로그인 응답 `401 Unauthorized` 확인.
- `curl -I http://localhost:8000/index.html` 응답 `200 OK` 확인.
- `curl -I http://localhost:8000/.env` 응답 `403 Forbidden` 확인.
- Playwright로 `/`, `/health.json`, `/api/me`, `/index.html`, `/.env` 경로 접속을 확인했다.

### 메모

- Task 6에서 근무 기록 생성/수정/삭제를 구현할 때 `AuditLogService::recordInTransaction()`을 사용해 근무 기록 변경과 감사 로그 기록을 하나의 트랜잭션으로 묶는다.

## Task 6. 근무 기록 CRUD와 기간별 조회/요약 API 구현

상태: [x]

### 목표

사용자별 근무시간 등록, 기간 조회, 합계 조회, 수정, soft delete API를 구현한다.

### 작업 범위

- `WorkEntryController` 구현
- `WorkEntryService` 구현
- `POST /api/work-entries`
- `GET /api/work-entries?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `GET /api/work-entries/summary?from=YYYY-MM-DD&to=YYYY-MM-DD`
- `PATCH /api/work-entries/:id`
- `DELETE /api/work-entries/:id`
- 종료 시간 검증
- 동일 사용자 동일 시간대 중복 방지
- 휴게시간과 실제 근무시간 계산
- 수정 시 `version` 증가
- 변경 시 감사 로그 기록
- 본인/관리자 권한 검증

### 관련 파일

- `app/Controllers/`
- `app/Services/`
- `migrations/002_create_work_entries.sql`
- `docs/PRD.md`

### 완료 기준

- 로그인 사용자가 본인 근무 기록을 생성, 조회, 수정, soft delete할 수 있다.
- 타인 기록 접근은 거부된다.
- 기간별 summary가 총 근무시간, 총 휴게시간, 실제 근무시간을 반환한다.
- 수정/삭제 시 `audit_logs`가 생성된다.

### 작업 결과

- `app/Services/WorkEntryService.php`를 추가해 근무 기록 생성, 기간 조회, 요약, 수정, soft delete를 구현했다.
- `app/Controllers/WorkEntryController.php`를 추가해 근무 기록 API 요청/응답 처리를 분리했다.
- `POST /api/work-entries`를 구현했다.
- `GET /api/work-entries?from=YYYY-MM-DD&to=YYYY-MM-DD`를 구현했다.
- `GET /api/work-entries/summary?from=YYYY-MM-DD&to=YYYY-MM-DD`를 구현했다.
- `PATCH /api/work-entries/:id`를 구현했다.
- `DELETE /api/work-entries/:id`를 구현했다.
- 종료 시간이 시작 시간보다 늦은지 검증하고, 휴게 시간이 전체 근무 시간보다 짧은지 검증하도록 구현했다.
- 동일 사용자의 `active` 근무 기록 시간대 중복을 차단하도록 구현했다.
- 총 근무 시간에서 휴게 시간을 차감해 `work_minutes`를 계산하도록 구현했다.
- 수정 시 `version`을 증가시키고, 삭제 시 `status=deleted`, `deleted_at`을 기록하는 soft delete로 처리했다.
- 일반 사용자는 본인 기록만 접근하고, 관리자는 `userId` 파라미터로 대상 사용자를 지정할 수 있도록 권한 기준을 구현했다.
- 생성/수정/삭제 시 `AuditLogService::recordInTransaction()`으로 근무 기록 변경과 감사 로그 기록을 하나의 트랜잭션으로 묶었다.

### 검증 결과

- `docker compose exec app php -l app/Services/WorkEntryService.php` 성공.
- `docker compose exec app php -l app/Controllers/WorkEntryController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- 검증용 사용자 `task6-20260519-2200@example.com` 회원가입 및 세션 쿠키 발급 확인.
- `POST /api/work-entries` 호출로 근무 기록 생성 확인. `09:00~18:00`, 휴게 60분 입력 시 `work_minutes=480` 확인.
- 같은 사용자의 겹치는 시간대 `17:00~20:00` 생성 요청이 `409 Conflict`로 거부되는 것을 확인.
- `GET /api/work-entries?from=2026-05-01&to=2026-05-31` 호출로 기간별 목록 조회 확인.
- `GET /api/work-entries/summary?from=2026-05-01&to=2026-05-31` 호출로 `gross_minutes=540`, `break_minutes=60`, `work_minutes=480` 요약 확인.
- `PATCH /api/work-entries/1` 호출로 종료 시간, 휴게 시간, 메모 수정 확인. 수정 후 `version=2`, `work_minutes=510` 확인.
- `DELETE /api/work-entries/1` 호출로 soft delete 확인. 삭제 후 `status=deleted`, `deleted_at` 값, `version=3` 확인.
- 삭제 후 목록 조회 결과 `entries=[]`, 요약 결과 `total_entries=0`, `work_minutes=0` 확인.
- 일반 사용자가 다른 `userId`로 근무 기록 생성을 시도하면 `403 Forbidden`으로 거부되는 것을 확인.
- 앱 DB 계층으로 `create_work`, `update_work`, `delete_work` 감사 로그가 저장되고, 수정/삭제 로그에 `before_json`, `after_json`이 함께 저장되는 것을 확인.
- Playwright로 `/`, `/health.json`, `/api/me`, `/api/work-entries?from=2026-05-01&to=2026-05-31`, `/index.html`, `/.env` 경로 접속을 확인했다.

### 메모

- 현재 근무 기록 API는 JSON API만 제공한다. 브라우저용 입력/조회 화면은 Task 7에서 Bootstrap 기반 서버 렌더링 UI로 구현한다.

## Task 7. Bootstrap 기반 서버 렌더링 UI와 기존 AI 파서 정리

상태: [x]

### 목표

Bootstrap 5 계열을 적용해 로그인, 회원가입, 근무시간 입력, 기간 조회, 근무 기록 테이블, 상태 알림 화면을 구성한다.

### 작업 범위

- 공통 layout, header, navigation 구성
- 로그인 폼
- 회원가입 폼
- 근무시간 입력 폼
- 기간 필터
- 근무 기록 테이블
- alert, badge, table, form Bootstrap class 적용
- 모바일 폭에서 폼과 필터가 겹치지 않도록 정리
- 기존 `/index.html` AI 파서 접근 유지

### 관련 파일

- `index.php`
- `public/`
- `index.html`
- `styles.css`
- `docs/PRD.md`

### 완료 기준

- 브라우저에서 로그인, 회원가입, 근무시간 입력, 조회 화면을 사용할 수 있다.
- 모바일 폭에서 입력 폼과 기간 필터가 겹치지 않는다.
- 기존 `/index.html` AI 파서 접근이 유지된다.

### 작업 결과

- `app/Controllers/WebController.php`를 추가해 서버 렌더링 화면 요청/응답을 분리했다.
- Bootstrap 5 CDN 기반 공통 레이아웃, 상단 내비게이션, alert, form, table, summary card를 구성했다.
- `/`는 로그인 상태에 따라 `/login` 또는 `/work-entries`로 이동하도록 변경했다.
- `/login`, `/signup`, `POST /login`, `POST /signup`, `POST /logout` 화면 흐름을 구현했다.
- `/work-entries`에서 기간 필터, 기간 요약, 근무시간 입력 폼, 근무 기록 테이블을 사용할 수 있도록 구현했다.
- `/work-entries/:id/edit`에서 근무 기록 수정 화면과 수정 POST를 구현했다.
- `/work-entries/:id/delete`에서 근무 기록 soft delete POST를 구현했다.
- 기존 환경 점검 화면은 `/health`, JSON 점검은 `/health.json`으로 유지했다.
- 기존 `/index.html` AI 근무시간 파서 접근을 유지했다.
- 모바일 폭에서 필터와 입력 폼이 세로로 정렬되도록 Bootstrap grid를 적용했다.

### 검증 결과

- `docker compose exec app php -l app/Controllers/WebController.php` 성공.
- `docker compose exec app php -l app/Services/WorkEntryService.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- `curl http://localhost:8000/` 응답이 `/login`으로 리다이렉트되는 것을 확인했다.
- `curl http://localhost:8000/login`, `/signup` 응답 `200 OK` 확인.
- 미로그인 `curl http://localhost:8000/work-entries` 응답이 `/login`으로 리다이렉트되는 것을 확인했다.
- 회원가입 폼 POST로 `task7-20260519-2251@example.com` 사용자를 생성하고 `/work-entries` 리다이렉트를 확인했다.
- 로그인 세션으로 `/work-entries?from=2026-05-01&to=2026-05-31` 화면 접근과 빈 목록 표시를 확인했다.
- 근무시간 입력 폼 POST로 `2026-05-20 09:00~18:00`, 휴게 60분 기록 저장을 확인했다.
- 저장 후 근무시간 화면에서 요약 `근무일 1일`, `기록 1건`, `실근무 8:00`과 테이블 행 표시를 확인했다.
- `/work-entries/2/edit` 수정 화면 접근과 수정 POST 동작을 확인했다.
- `/work-entries/2/delete` 삭제 POST 후 목록에서 제외되는 것을 확인했다.
- `curl -I http://localhost:8000/index.html` 응답 `200 OK` 확인.
- `curl -I http://localhost:8000/.env` 응답 `403 Forbidden` 확인.
- Playwright 데스크톱 폭에서 `/login`, `/signup`, `/work-entries`, `/health`, `/index.html`, `/.env` 경로 접속을 확인했다.
- Playwright 모바일 폭 `390x844`에서 `/signup` 레이아웃이 겹치지 않는 것을 확인했다.

### 메모

- 현재 UI는 PHP 서버 렌더링 form POST 기반이다. CSRF 보호는 Task 9 보안 hardening에서 추가한다.

## Task 8. 웹훅 보안 검증과 텔레그램 발송 구현

상태: [x]

### 목표

`POST /api/webhooks/work-summary`를 구현한다. IP allowlist, 유효 기간, shared secret, `requestId` 중복 방지, 텔레그램 발송, 로그 기록을 포함한다.

### 작업 범위

- `WebhookController` 구현
- `WebhookService` 구현
- `TelegramService` 구현
- source IP 추출
- `WEBHOOK_ALLOWED_IPS` 검증
- `WEBHOOK_ACTIVE_FROM`, `WEBHOOK_ACTIVE_TO` 검증
- shared secret 검증
- `requestId` 중복 검증
- 기간별 근무 요약 계산
- 사용자별 `telegram_chat_id` 우선 사용
- 없으면 `TELEGRAM_DEFAULT_CHAT_ID` 사용
- 성공, 실패, 거부 결과를 `webhook_events`와 `audit_logs`에 기록

### 관련 파일

- `app/Controllers/`
- `app/Services/`
- `migrations/004_create_webhook_events.sql`
- `.env.example`

### 완료 기준

- 허용되지 않은 IP 요청이 거부되고 로그에 남는다.
- 잘못된 secret 요청이 거부되고 로그에 남는다.
- 유효 기간 밖 요청이 거부되고 로그에 남는다.
- 중복 `requestId` 요청이 거부되고 로그에 남는다.
- 정상 요청은 근무기간 요약을 생성한다.
- 텔레그램 발송 성공 또는 실패 결과가 `webhook_events`와 `audit_logs`에 기록된다.

### 작업 결과

- `app/Controllers/WebhookController.php`를 추가해 `POST /api/webhooks/work-summary` 요청/응답을 분리했다.
- `app/Services/WebhookService.php`를 추가해 IP allowlist, 유효 기간, shared secret, `requestId` 중복 방지, 기간별 근무 요약 생성을 구현했다.
- `app/Services/TelegramService.php`를 추가해 Telegram Bot API `sendMessage` 호출을 구현했다.
- `X-Webhook-Secret` 헤더 또는 `Authorization: Bearer ...` 값으로 shared secret을 검증하도록 구현했다.
- `WEBHOOK_ALLOWED_IPS`, `WEBHOOK_ACTIVE_FROM`, `WEBHOOK_ACTIVE_TO`, `WEBHOOK_SHARED_SECRET` 환경변수를 사용하도록 구현했다.
- 사용자별 `telegram_chat_id`가 있으면 우선 사용하고, 없으면 `TELEGRAM_DEFAULT_CHAT_ID`를 사용하도록 구현했다.
- 텔레그램 토큰 또는 chat id가 비어 있으면 로컬 검증이 가능하도록 발송 실패/스킵 결과를 반환하고 `webhook_events.result=failed`로 기록한다.
- 성공, 실패, 거부 결과를 `webhook_events`에 기록하고, `webhook_summary` 또는 `webhook_rejected` 감사 로그를 기록하도록 구현했다.
- 중복 `requestId` 요청은 거부하고 감사 로그에 거부 사유를 남기도록 구현했다.

### 검증 결과

- `docker compose exec app php -l app/Services/TelegramService.php` 성공.
- `docker compose exec app php -l app/Services/WebhookService.php` 성공.
- `docker compose exec app php -l app/Controllers/WebhookController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- 검증용 사용자 `task8-20260519-2310@example.com` 회원가입 및 근무 기록 생성 확인.
- 허용되지 않은 IP에서 `POST /api/webhooks/work-summary` 호출 시 `403 Forbidden`, `result: rejected`, 사유 `허용되지 않은 IP입니다.` 확인.
- 허용 IP에서 잘못된 secret으로 호출 시 `403 Forbidden`, `result: rejected`, 사유 `웹훅 secret이 올바르지 않습니다.` 확인.
- 정상 IP, 유효 기간, 올바른 secret, 신규 `requestId`로 호출 시 근무 요약이 생성되고, 로컬 텔레그램 미설정으로 `502 Bad Gateway`, `result: failed`, `telegram.skipped: true`가 반환되는 것을 확인.
- 정상 요청의 요약에서 `total_entries=1`, `total_work_days=1`, `gross_minutes=540`, `break_minutes=60`, `work_minutes=480` 확인.
- 같은 `requestId` 재호출 시 `409 Conflict`, `result: rejected`, 사유 `이미 처리된 requestId입니다.` 확인.
- 서비스 레벨에서 `WEBHOOK_ACTIVE_TO`를 과거로 임시 오버라이드해 유효 기간 밖 요청이 `웹훅 유효 기간이 아닙니다.`로 거부되는 것을 확인.
- 앱 DB 계층으로 `webhook_events`에 `rejected`, `failed` 결과와 `allowed`, `error_message`가 저장된 것을 확인했다.
- 앱 DB 계층으로 `audit_logs`에 `webhook_summary`, `webhook_rejected` 이벤트가 저장된 것을 확인했다.
- `curl -I http://localhost:8000/`, `/index.html`, `/.env` 기본 경로 응답을 확인했다.
- Playwright로 `/`, `/health.json`, `/login`, `/index.html`, `/.env` 경로 접속을 확인했다.

### 메모

- 운영에서 실제 텔레그램 발송을 사용하려면 `.env`에 `TELEGRAM_BOT_TOKEN`과 `TELEGRAM_DEFAULT_CHAT_ID` 또는 사용자별 `telegram_chat_id`를 설정해야 한다.

## Task 9. 보안 hardening과 통합 검증

상태: [x]

### 목표

PRD 성공 기준과 TRD 보안 체크리스트를 기준으로 전체 흐름을 검증한다.

### 작업 범위

- 회원가입부터 근무 기록 등록, 조회, 수정, delete까지 end-to-end 검증
- 관리자 조회와 일반 사용자 접근 제한 검증
- 감사 로그 생성 여부 검증
- 웹훅 성공, 실패, 거부 시나리오 검증
- `docker compose up -d --build` 실행 절차 재검증
- HTTPS, secure cookie 운영 설정 정리
- CSRF 보호 방안 정리
- rate limit 방안 정리
- 데이터베이스 백업 정책 문서화
- `docs/PRD.md`, `docs/TRD.md`의 완료/제약 사항 업데이트

### 관련 파일

- `docs/PRD.md`
- `docs/TRD.md`
- `README.md`
- 전체 애플리케이션 파일

### 완료 기준

- PRD 성공 기준 6개가 검증되거나 미완료 사유가 문서화된다.
- `docker compose up -d --build` 후 핵심 화면과 API가 동작한다.
- 보안 체크리스트의 완료/미완료 상태가 TRD에 반영된다.

### 작업 결과

- 서버 렌더링 폼 전반에 CSRF 토큰을 추가했다.
- 로그인, 회원가입, 로그아웃, 근무 기록 생성, 수정, 삭제 요청에서 CSRF 토큰을 검증하도록 구현했다.
- HTML 응답, JSON API 응답, 최종 404 응답에 기본 보안 헤더를 추가했다.
- 운영 보안, HTTPS, secure cookie, rate limit, DB 백업 정책을 `docs/SECURITY_OPERATIONS.md`로 문서화했다.
- `docs/PRD.md`에 현재 성공 기준 검증 상태와 미완료 항목을 정리했다.
- `docs/TRD.md` 보안 체크리스트에 완료/미완료 상태를 반영했다.
- `README.md` 문서 목록에 운영 보안 문서를 추가했다.

### 검증 결과

- `docker compose up -d --build`로 PHP 8.4/Apache, MySQL 8.0 컨테이너 재빌드와 실행을 확인했다.
- `docker compose exec app php -l app/Services/CsrfService.php` 성공.
- `docker compose exec app php -l app/Controllers/WebController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- `GET /signup` 응답에서 CSRF hidden input과 `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` 헤더를 확인했다.
- `GET /api/me` 비로그인 JSON 응답에서 `401 Unauthorized`와 기본 보안 헤더를 확인했다.
- `.env` 직접 접근이 `403 Forbidden`으로 차단되는 것을 확인했다.
- CSRF 토큰 없는 회원가입 POST가 `/signup`으로 되돌아가고, 유효한 토큰이 있는 회원가입 POST는 `/work-entries`로 이동하는 것을 확인했다.
- CSRF 토큰 없는 근무 기록 생성 요청은 기록을 생성하지 않고, 유효한 토큰 요청만 생성되는 것을 확인했다.
- 같은 세션에서 근무 기록 수정 후 `work_minutes=120`, `version=2`로 반영되는 것을 확인했다.
- 같은 세션에서 근무 기록 삭제 후 기간 조회 결과에서 제외되는 것을 확인했다.
- MySQL에서 `signup`, `create_work`, `update_work`, `delete_work` 감사 로그와 해시 기록 생성을 확인했다.
- Playwright로 모바일 폭 회원가입 화면, `/`, `/health.json`, `/index.html`, `/.env`, `/work-entries` 라우팅을 확인했다.

### 메모

- 서버 렌더링 폼 CSRF 보호는 적용 완료했다. JSON API는 같은 출처 클라이언트 또는 API 클라이언트 사용을 전제로 하며, 운영 전 API CSRF/rate limit 정책은 별도 결정이 필요하다.
- 운영 HTTPS, secure cookie 강제, 내부 rate limit, 백업 자동화는 배포 환경에서 마무리해야 한다.

## Task 10. 근무 기록 홈, 입력, 조회 화면 분리

상태: [x]

### 목표

로그인 후 진입하는 `/work-entries`를 근무 기록 홈으로 정리하고, 근무시간 입력과 근무시간 조회를 각각 별도 화면에서 사용하도록 분리한다.

### 작업 범위

- `/work-entries` 최근 근무 기록 10건과 기능 진입 버튼 구성
- `/work-entries/create` 근무시간 입력 화면 추가
- 근무시간 입력 화면의 근무일 기본값을 오늘 날짜로 설정
- 시작/종료 시간은 시 select와 5분 단위 분 select로 입력
- `/work-entries/search` 기간 조회, 요약, 목록 화면 추가
- 홈과 조회 결과 양쪽에서 수정, 삭제 작업 유지
- 입력 저장 후 홈으로 이동
- 조회 화면에 이번달, 지난달 빠른 선택 제공

### 관련 파일

- `index.php`
- `app/Controllers/WebController.php`
- `app/Services/WorkEntryService.php`
- `docs/TRD.md`

### 완료 기준

- 로그인 후 `/work-entries`에서 최근 근무 기록과 `근무시간 입력`, `근무시간 조회` 버튼이 표시된다.
- `/work-entries/create`에서 새 근무 기록을 저장하면 `/work-entries`로 이동한다.
- `/work-entries/search`에서 이번달, 지난달, 직접 기간 조회가 동작한다.
- 홈과 조회 결과에서 수정, 삭제 후 적절한 화면으로 돌아간다.
- Docker 실행 환경에서 Playwright로 핵심 화면과 라우팅을 확인한다.

### 작업 결과

- `/work-entries`를 최근 근무 기록 10건과 `근무시간 입력`, `근무시간 조회` 버튼을 제공하는 홈 화면으로 정리했다.
- `/work-entries/create` 입력 화면을 추가했다.
- `/work-entries/search` 기간 조회, 요약, 목록 화면을 추가했다.
- 근무일 기본값을 오늘 날짜로 설정하고, 시작/종료 시간은 시 select와 5분 단위 분 select로 변경했다.
- `WorkEntryService::recent()`를 추가해 홈의 최근 기록을 조회하도록 했다.
- 수정, 삭제 후 홈 또는 조회 화면으로 돌아갈 수 있도록 `returnTo` 경로를 유지했다.
- `docs/TRD.md`의 서버 렌더링 화면 경로를 새 구조로 갱신했다.

### 검증 결과

- `docker compose exec app php -l app/Controllers/WebController.php` 성공.
- `docker compose exec app php -l app/Services/WorkEntryService.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- 로그인 세션에서 `/work-entries`, `/work-entries/create`, `/work-entries/search`가 각각 `200 OK`로 응답하는 것을 확인했다.
- `/work-entries` 홈에서 `근무시간 입력`, `근무시간 조회`, 최근 근무 기록 영역이 표시되는 것을 확인했다.
- `/work-entries/create`에서 근무일 기본값 `2026-05-21`, 시작 `09:00`, 종료 `18:00` select 기본값을 확인했다.
- 새 시/분 select 필드로 `09:05~10:10` 기록 저장 시 `work_minutes=65`로 반영되는 것을 확인했다.
- 5분 단위가 아닌 `07분` POST는 `/work-entries/create`로 되돌아가고 기록을 생성하지 않는 것을 확인했다.
- 입력 POST 저장 후 `/work-entries`로 이동하는 것을 확인했다.
- `/work-entries/search?from=2026-05-01&to=2026-05-31`에서 요약과 목록, 이번달/지난달 링크를 확인했다.
- 조회 화면에서 수정 진입 시 `returnTo`가 유지되고, 수정 후 조회 화면으로 복귀하는 것을 확인했다.
- 삭제 후 조회 화면으로 복귀하고 API 목록에서 제외되는 것을 확인했다.
- CSRF 없는 입력 POST가 `/work-entries/create`로 되돌아가는 것을 확인했다.
- Playwright 모바일 폭에서 홈, 입력, 조회, 수정 화면 라우팅과 레이아웃을 확인했다.
- Playwright 모바일 폭에서 시작/종료 기본값이 `09:00`, `18:00`이고 분 선택 옵션이 `00, 05, ..., 55`인 것을 확인했다.
- Playwright 모바일 폭에서 근무일 input, 시작/종료 select, 휴게 input의 높이와 라벨 간격이 맞는 것을 확인했다.

### 메모

- 입력 화면은 빠른 입력을 위해 시작 `09:00`, 종료 `18:00`, 휴게 `0`을 기본값으로 제공한다.
- 분 선택은 모바일 사용성을 고려해 `00, 05, 10, ..., 55`만 제공한다.

## Task 11. 정기 발송 설정 UI와 실행 신호 웹훅 구현

상태: [x]

### 목표

공유서버에서 cron을 사용할 수 없으므로, 개인서버가 매일 이 프로젝트 웹훅을 호출하면 앱 내부에서 해당 일자에 발송해야 하는 정기 발송 설정을 조회해 Telegram 또는 Discord로 근무 요약을 전송한다.

### 작업 범위

- 사용자별 정기 발송 설정 테이블 추가
- `/notification-settings` 설정 UI 추가
- `/work-entries` 홈에 정기 발송 설정 진입 버튼 추가
- Telegram Bot token/chat id 저장
- Discord Webhook URL 저장
- 요약 기간 기준과 매월 발송일 저장
- 전송 채널 선택에 따라 Telegram/Discord 입력 영역 전환
- `POST /api/webhooks/work-summary`에서 `triggerDate/requestId` 실행 신호 payload 지원
- 실행 신호 수신 시 `monthly_send_day`가 맞는 활성 설정을 조회해 일괄 발송
- 기존 `userId/from/to` 단건 웹훅 payload 호환 유지

### 관련 파일

- `migrations/005_create_notification_settings.sql`
- `app/Services/NotificationSettingService.php`
- `app/Services/DiscordService.php`
- `app/Services/WebhookService.php`
- `app/Controllers/WebController.php`
- `index.php`
- `docs/TRD.md`

### 완료 기준

- 사용자가 정기 발송 설정 화면에서 Telegram 또는 Discord 설정을 저장할 수 있다.
- 사용자가 `지난달`, `이번달 현재까지`, `최근 7일`, `직접 지정` 중 요약 기간 기준을 선택할 수 있다.
- 전송 채널을 바꾸면 해당 채널 입력 항목만 표시된다.
- 저장된 민감정보는 화면에서 마스킹된다.
- 외부서버가 `triggerDate/requestId`만 보내도 앱 내부에서 오늘 발송 대상을 조회한다.
- 발송 성공/실패/거부 결과가 `webhook_events`와 `audit_logs`에 남는다.
- 기존 단건 웹훅 호출 방식은 깨지지 않는다.

### 작업 결과

- `notification_settings` 테이블 마이그레이션을 추가했다.
- 사용자당 하나의 정기 발송 설정을 저장하도록 `NotificationSettingService`를 추가했다.
- 고정 시작일/종료일 대신 `summary_period_type` 기준으로 실행 시점에 요약 기간을 계산하도록 변경했다.
- Discord Webhook URL 발송을 위한 `DiscordService`를 추가했다.
- `TelegramService`가 설정별 Bot token/chat id를 사용할 수 있도록 확장했다.
- `/notification-settings` GET/POST 화면을 추가하고 CSRF 보호를 적용했다.
- `/notification-settings`의 요약 기간 설정을 퀵버튼 형태로 정리하고, 채널 선택에 따라 Telegram/Discord 입력 영역을 전환하도록 했다.
- 정기 발송 설정 변경 시 민감정보를 마스킹한 감사 로그를 기록하도록 했다.
- `WebhookService`에 실행 신호 payload 처리 경로를 추가했다.
- 실행 신호 payload 예시는 `{"triggerDate":"YYYY-MM-DD","requestId":"scheduled-work-summary-YYYY-MM-DD"}` 형식으로 정리했다.
- 개인서버 IP는 운영 `.env`의 `WEBHOOK_ALLOWED_IPS`에 포함되어야 한다.

### 검증 결과

- `migrations/005_create_notification_settings.sql`을 로컬 MySQL에 적용했다.
- 로컬 MySQL의 `notification_settings` 테이블을 `summary_period_type`, `custom_period_from`, `custom_period_to` 구조로 보정했다.
- `docker compose exec app php -l app/Services/NotificationSettingService.php` 성공.
- `docker compose exec app php -l app/Services/DiscordService.php` 성공.
- `docker compose exec app php -l app/Services/WebhookService.php` 성공.
- `docker compose exec app php -l app/Controllers/WebController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- 로그인 세션에서 `/notification-settings`가 `200 OK`로 열리는 것을 확인했다.
- Discord 설정 저장 후 채널, 요약 기간 기준, 발송일, 마스킹된 Webhook URL 표시를 확인했다.
- Playwright 모바일 화면에서 전송 채널을 Discord로 변경하면 Telegram 입력 영역이 숨겨지고 Discord Webhook URL 입력 영역만 표시되는 것을 확인했다.
- Playwright 모바일 화면에서 `이번달 현재까지` 요약 기간 기준을 선택하고 저장하면 선택 상태와 마스킹된 Webhook URL이 유지되는 것을 확인했다.
- Playwright 모바일 화면에서 문서 너비와 화면 너비가 동일해 수평 overflow가 없음을 확인했다.
- 앱 컨테이너 내부 localhost에서 실행 신호 웹훅 호출 시 오늘 발송 대상 1건을 조회하는 것을 확인했다.
- 앱 컨테이너 내부 localhost에서 실행 신호 웹훅 호출 시 설정별 `summary_period_type`에 따라 `custom`, `previous_month`, `current_month` 기간이 계산되는 것을 확인했다.
- 테스트 Discord URL이 닫힌 포트라 발송 실패가 발생했고, 응답에 `dueCount=1`, `failedCount=1`, 요약 `work_minutes=65`가 반환되는 것을 확인했다.
- 허용 IP가 아닌 호스트 직접 호출은 `허용되지 않은 IP입니다.`로 거부되는 것을 확인했다.

### 메모

- 공유서버에서는 cron을 구성하지 않는다. 개인서버에서 매일 정각에 이 프로젝트 웹훅을 호출한다.
- 운영 전 개인서버 공인 IP를 `WEBHOOK_ALLOWED_IPS`에 추가해야 한다.
- 현재 민감정보는 DB에 평문 저장하고 화면과 감사 로그에서만 마스킹한다.

## Task 12. 근무시간 일괄 입력과 웹훅 수신 로그 보강

상태: [x]

### 목표

사용자가 월/일과 시간 범위를 여러 줄로 입력하면 정규 포맷 파서 또는 AI 변환으로 근무 기록 후보를 만들고, 사용자가 확인한 뒤 일괄 저장할 수 있게 한다. 공유호스팅에서 웹서버 로그를 직접 보기 어려운 상황을 대비해 웹훅 수신 요청도 DB에 별도 기록한다.

### 작업 범위

- `/work-entries/import` 일괄 입력 화면 추가
- 정규 포맷 파서 구현
- AI Provider/model/API Key 설정 저장
- API Key 암호화 저장과 화면 마스킹
- 변환 결과 미리보기 후 확인 저장 흐름 구현
- `WorkEntryService::bulkCreate()` 일괄 저장 트랜잭션 구현
- 웹훅 raw request 수신 로그 테이블 추가
- JSON 파싱 실패 웹훅 요청도 DB에 기록

### 관련 파일

- `migrations/006_create_user_ai_settings.sql`
- `migrations/007_create_webhook_request_logs.sql`
- `app/Services/AiCredentialService.php`
- `app/Services/WorkEntryImportService.php`
- `app/Services/WebhookRequestLogService.php`
- `app/Services/WorkEntryService.php`
- `app/Controllers/WebController.php`
- `index.php`
- `.env.example`
- `docs/TRD.md`
- `docs/DOCKER_GUIDE.md`

### 완료 기준

- `/work-entries/import`에서 기준 연도, 변환 방식, AI 설정, 원문 텍스트를 입력할 수 있다.
- `04/13 9:30 - 10:00 , 14:15 - 16:15` 같은 포맷이 여러 근무 기록 후보로 변환된다.
- 미리보기 화면에서 확인 후 일괄 저장하면 각 기록이 감사 로그와 함께 저장된다.
- 사용자 AI API Key는 암호화되어 저장되고 화면에는 마스킹 힌트만 표시된다.
- 웹훅 요청은 처리 결과와 별도로 수신 로그가 DB에 남는다.
- 깨진 JSON 웹훅 요청도 `invalid_json` 상태로 추적 가능하다.

### 작업 결과

- `user_ai_settings` 테이블 마이그레이션을 추가했다.
- `webhook_request_logs` 테이블 마이그레이션을 추가했다.
- `AiCredentialService`를 추가해 AI API Key를 AES-256-GCM으로 암호화 저장하도록 했다.
- `WorkEntryImportService`를 추가해 정규 포맷 파싱과 AI 변환 호출 경로를 구성했다.
- `/work-entries/import`, `/work-entries/import/preview`, `/work-entries/import` POST 흐름을 추가했다.
- 일괄 저장은 `WorkEntryService::bulkCreate()`에서 한 트랜잭션으로 처리하고 `bulk_import_work` 감사 로그를 남기도록 했다.
- `WebhookRequestLogService`를 추가해 웹훅 수신 요청의 path, method, source IP, 안전한 헤더, body hash, raw body, parse status를 DB에 저장하도록 했다.
- `Authorization`, `X-Webhook-Secret`, `Cookie` 헤더는 수신 로그에서 제외했다.
- `.env.example`과 문서의 예시 비밀번호를 실제처럼 보이는 값에서 `change-this-*` placeholder로 변경했다.

### 검증 결과

- `migrations/006_create_user_ai_settings.sql`을 로컬 MySQL에 적용했다.
- `migrations/007_create_webhook_request_logs.sql`을 로컬 MySQL에 적용했다.
- `docker compose exec app php -l app/Services/AiCredentialService.php` 성공.
- `docker compose exec app php -l app/Services/WorkEntryImportService.php` 성공.
- `docker compose exec app php -l app/Services/WebhookRequestLogService.php` 성공.
- `docker compose exec app php -l app/Services/WorkEntryService.php` 성공.
- `docker compose exec app php -l app/Controllers/WebController.php` 성공.
- `docker compose exec app php -l index.php` 성공.
- Playwright 모바일 화면에서 `/work-entries/import` 접근, 정규 포맷 입력, 미리보기 4건 생성, 확인 후 일괄 저장을 검증했다.
- 일괄 저장 후 `/work-entries/search?from=2026-04-04&to=2026-04-13`로 이동하고 4건 저장 및 요약 `15:30` 표시를 확인했다.
- Playwright 모바일 화면에서 문서 너비와 화면 너비가 동일해 수평 overflow가 없음을 확인했다.
- 깨진 JSON 웹훅 요청을 호출해 `webhook_request_logs.parse_status=invalid_json`으로 저장되는 것을 확인했다.

### 메모

- 정규 포맷으로 해석 가능한 입력은 AI 호출 없이 변환한다.
- AI 변환은 저장된 사용자 API Key가 있을 때만 사용한다.
- 웹훅 raw body에는 민감정보를 넣지 않는 운영 정책이 필요하다.
