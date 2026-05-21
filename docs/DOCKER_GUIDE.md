# Docker Guide

이 문서는 근무시간 관리 시스템의 로컬 Docker 실행 환경을 설명합니다.

## 구성

- PHP 8.4 Apache 컨테이너
- MySQL 8.0 컨테이너
- PDO MySQL 확장
- `.env` 기반 환경변수
- 프로젝트 루트 bind mount

## 주요 파일

```text
.
├── Dockerfile
├── docker-compose.yml
├── docker/
│   └── apache/
│       └── 000-default.conf
├── .env.example
└── .env
```

## 환경변수

로컬 실행 시 `.env` 파일을 사용합니다. 새 환경에서는 `.env.example`을 복사해 `.env`를 만듭니다.

```bash
cp .env.example .env
```

기본값:

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
```

`.env`는 민감 정보를 포함할 수 있으므로 git에 커밋하지 않습니다.

## 실행

```bash
docker compose up -d --build
```

브라우저에서 확인:

```text
http://localhost:8000
```

기존 AI 근무시간 파서:

```text
http://localhost:8000/index.html
```

## 상태 확인

```bash
docker compose ps
```

PHP 버전 확인:

```bash
docker compose exec app php -v
```

PHP 확장 확인:

```bash
docker compose exec app php -m
```

MySQL 접속 확인:

```bash
docker compose exec mysql sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -D "$MYSQL_DATABASE" -e "SELECT VERSION();"'
```

## 로그 확인

앱 로그:

```bash
docker compose logs -f app
```

MySQL 로그:

```bash
docker compose logs -f mysql
```

## 중지

```bash
docker compose down
```

## 데이터 초기화

MySQL volume까지 삭제합니다.

```bash
docker compose down -v
```

## 포트

| 대상 | 기본 포트 |
| --- | --- |
| 웹 앱 | `localhost:8000` |
| MySQL | `localhost:3307` |

포트는 `.env`에서 변경할 수 있습니다.

```env
APP_PORT=8000
MYSQL_PORT=3307
```

## 문제 해결

### Docker daemon 권한 오류

WSL에서 아래 오류가 나면 Docker Desktop의 WSL Integration을 확인합니다.

```text
permission denied while trying to connect to the docker API
```

확인할 항목:

- Docker Desktop 실행 여부
- Docker Desktop Settings > Resources > WSL Integration 활성화 여부
- WSL 재시작 여부

PowerShell:

```powershell
wsl --shutdown
```

그 다음 WSL을 다시 열고 확인합니다.

```bash
docker info
docker compose version
```

### 포트 충돌

이미 `8000` 또는 `3307` 포트를 사용 중이면 `.env`에서 포트를 변경합니다.

```env
APP_PORT=8080
MYSQL_PORT=3308
```

변경 후 다시 실행합니다.

```bash
docker compose up -d
```
