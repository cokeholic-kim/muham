# 근무시간 관리 시스템

근무시간 입력, 조회, 기간별 정리, 외부 웹훅 기반 텔레그램 알림을 제공하는 근무시간 관리 프로그램입니다.

현재 프로젝트는 PHP 8.4, MySQL 8.0, PDO 기반으로 구축 중입니다. 기존 정적 페이지에는 자유 형식 근무 기록을 AI로 파싱해 JSON, 표, 차트로 확인하는 프로토타입이 포함되어 있습니다.

## 문서

- [PRD: 제품 요구사항](docs/PRD.md)
- [TRD: 기술 구현 문서](docs/TRD.md)
- [Implementation Plan: 구현 계획](docs/IMPLEMENTATION_PLAN.md)
- [Docker Guide: 로컬 실행 환경](docs/DOCKER_GUIDE.md)
- [Deploy Guide: GitHub Actions 배포](docs/DEPLOY_GUIDE.md)
- [Security Operations Guide: 운영 보안](docs/SECURITY_OPERATIONS.md)

## 기술 스택

- PHP 8.4
- MySQL 8.0
- PDO
- Docker Compose
- Bootstrap 5 계열 디자인 시스템
