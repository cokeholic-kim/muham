# Security Operations Guide

이 문서는 운영 환경에서 근무시간 관리 시스템을 안전하게 운영하기 위한 보안, 제한, 백업 기준을 정리합니다.

## HTTPS와 쿠키

- 운영 환경은 반드시 HTTPS 뒤에서 실행합니다.
- `.env`의 `APP_ENV=production`을 사용하면 세션 쿠키에 `Secure` 속성이 적용됩니다.
- 세션 쿠키는 `HttpOnly`, `SameSite=Lax`로 설정되어 있습니다.
- 프록시나 로드밸런서를 사용할 경우 `X-Forwarded-Proto: https`가 애플리케이션까지 전달되어야 합니다.

## CSRF 보호

- 서버 렌더링 화면의 모든 POST 폼은 `_csrf_token`을 포함합니다.
- 토큰은 PHP session에 저장하고 `hash_equals()`로 검증합니다.
- CSRF 검증 실패 시 요청을 처리하지 않고 화면으로 되돌립니다.
- JSON API는 세션 기반 브라우저 폼이 아니라 API 클라이언트용으로 유지합니다. 운영 공개 범위에 따라 별도 토큰 또는 CSRF 정책을 추가 검토합니다.

## 보안 헤더

애플리케이션은 HTML/JSON 응답에 다음 헤더를 적용합니다.

- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: same-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`

운영 HTTPS에서는 웹서버 또는 프록시에서 HSTS 적용을 권장합니다.

## Rate Limit

애플리케이션 내부 rate limit은 아직 구현하지 않았습니다.

운영 전 최소 적용 기준:

- 로그인 실패: IP와 이메일 기준으로 분당 요청 수 제한
- 회원가입: IP 기준으로 분당 요청 수 제한
- 웹훅: IP와 `requestId` 기준으로 분당 요청 수 제한

권장 적용 위치:

- 1차: Nginx, Apache, CDN, WAF 같은 외부 계층
- 2차: 애플리케이션 내부 `rate_limit_events` 테이블 또는 Redis 기반 제한

## 감사 로그와 정합성

- 인증, 근무 기록 변경, 웹훅 처리 결과는 `audit_logs`에 기록합니다.
- 감사 로그는 `prev_hash`와 `hash`로 연결됩니다.
- 애플리케이션에는 감사 로그 수정/삭제 기능을 제공하지 않습니다.
- 운영 DB 권한은 애플리케이션 계정이 필요한 테이블 권한만 갖도록 제한합니다.

## 데이터베이스 백업

권장 백업 정책:

- 매일 1회 전체 백업
- 운영 변경 직전 수동 백업
- 최소 30일 보관
- 백업 파일 암호화
- 월 1회 복구 리허설

예시:

```bash
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  -h "$DB_HOST" \
  -P "$DB_PORT" \
  -u "$DB_USERNAME" \
  -p"$DB_PASSWORD" \
  "$DB_DATABASE" > "backup-$(date +%Y%m%d-%H%M%S).sql"
```

백업 대상에는 최소한 `users`, `work_entries`, `audit_logs`, `webhook_events`가 포함되어야 합니다.

## 운영 전 미완료 항목

- 애플리케이션 내부 rate limit 구현
- 관리자용 감사 로그 조회 화면
- 감사 로그 DB 권한 분리 또는 append-only 저장소 연동
- 텔레그램 운영 토큰과 chat id 설정 후 실제 발송 검증
