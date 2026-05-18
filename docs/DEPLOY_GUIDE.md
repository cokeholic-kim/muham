# Deploy Guide

이 문서는 `main` 브랜치 push 시 GitHub Actions로 원격 서버의 `html` 폴더에 소스코드를 배포하는 방법을 설명합니다.

## 방식

- 트리거: `main` 브랜치 push
- 수동 실행: GitHub Actions `workflow_dispatch`
- 접속 방식: SSH
- 인증 방식: password
- 업로드 방식: `tar` 아카이브 생성 후 `scp` 업로드, 원격 `tar` 압축 해제
- 대상 경로: GitHub Secret `SSH_TARGET_DIR`

워크플로 파일:

```text
.github/workflows/deploy-ssh.yml
```

## GitHub Secrets

GitHub 저장소에서 아래 Secrets를 설정합니다.

경로:

```text
Repository > Settings > Secrets and variables > Actions > New repository secret
```

필수:

| Secret | 설명 |
| --- | --- |
| `SSH_HOST` | 원격 서버 호스트 또는 IP |
| `SSH_USER` | SSH 사용자명 |
| `SSH_PASSWORD` | SSH 비밀번호 |
| `SSH_PORT` | SSH 포트 |
| `SSH_TARGET_DIR` | 원격 서버의 배포 대상 경로 |

예시:

```text
SSH_HOST=example.com
SSH_USER=myuser
SSH_PASSWORD=서버비밀번호
SSH_PORT=2222
SSH_TARGET_DIR=/home/myuser/html
```

`SSH_TARGET_DIR`는 서버의 실제 `html` 폴더 경로로 지정합니다. 계정 홈 아래 `html` 폴더를 사용한다면 보통 `/home/{계정명}/html` 형태입니다.

## 배포 제외 항목

웹루트에 공개되면 안 되거나 운영 서버에 필요 없는 파일은 배포에서 제외합니다.

- `.git/`
- `.github/`
- `.env`
- `.agents/`
- `.codex/`
- `docs/`
- `docker/`
- `docker-compose.yml`
- `Dockerfile`
- `.dockerignore`
- `logs/`
- `vendor/`

## 동작 순서

1. `main` 브랜치에 push합니다.
2. GitHub Actions가 저장소를 checkout합니다.
3. SSH 접속 정보 Secret이 있는지 확인합니다.
4. 원격 서버에 `SSH_TARGET_DIR` 폴더를 생성합니다.
5. 원격 서버에서 배포에 필요한 명령어와 쓰기 권한을 확인합니다.
6. 배포 아카이브를 생성해 원격 배포 디렉터리에 임시 파일로 업로드합니다.
7. 원격 `html` 폴더에서 서버 관리 파일을 제외한 기존 파일을 정리합니다.
8. 업로드한 아카이브를 원격 `html` 폴더에 압축 해제합니다.

## 원격 서버 사전 점검

GitHub Actions 실행 전에 로컬 터미널에서 아래 값을 실제 서버 정보로 바꿔 확인할 수 있습니다.

```bash
SSH_HOST=example.com
SSH_USER=myuser
SSH_PORT=2222
SSH_TARGET_DIR=/home/myuser/html
```

SSH 접속과 원격 명령어를 확인합니다.

```bash
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" '
  set -eu
  for cmd in sh tar mkdir rm; do
    command -v "$cmd" >/dev/null || {
      echo "Missing remote command: $cmd" >&2
      exit 1
    }
  done
  test -w "'"$SSH_TARGET_DIR"'"
'
```

업로드 경로 권한까지 확인하려면 작은 파일을 배포 디렉터리에 올렸다 지웁니다.

```bash
printf 'deploy test\n' > /tmp/muham-deploy-test.txt
scp -P "$SSH_PORT" /tmp/muham-deploy-test.txt "$SSH_USER@$SSH_HOST:$SSH_TARGET_DIR/.muham-deploy-test.txt"
ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST" 'rm -f "'"$SSH_TARGET_DIR"'/.muham-deploy-test.txt"'
rm -f /tmp/muham-deploy-test.txt
```

## 주의사항

- `.env`는 배포하지 않습니다.
- 운영 서버에 필요한 환경변수 파일은 서버에서 직접 관리합니다.
- 원격 `html` 폴더 안에 `.env`를 둘 경우 `.htaccess`로 외부 접근을 반드시 차단해야 합니다.
- 배포 시 원격 `html` 폴더 안의 기존 파일은 정리될 수 있습니다.
- 단, 서버에서 직접 관리하는 `.env`, `logs/`, `vendor/`는 삭제하지 않습니다.
- 원격 서버에는 `sh`, `tar`, `mkdir`, `rm`이 필요합니다. `rsync`, `bash`, `find`는 필요하지 않습니다.
- SSH key 방식이 가능해지면 password 방식보다 SSH key 방식으로 전환하는 것을 권장합니다.

## `.env` 접근 차단 확인

이 저장소는 Apache용 `.htaccess`를 포함합니다. 배포 후 아래 주소가 `403` 또는 `404`로 막히는지 확인합니다.

```text
https://도메인/.env
```

응답에 `.env` 내용이 보이면 즉시 배포를 중단하고 웹서버 설정을 수정해야 합니다.

가능하면 운영 `.env`는 `html` 폴더 밖에 두는 것을 권장합니다.
