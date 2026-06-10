# Personal Knowledge Blog

WordPress 기반 개인 지식 블로그 프로젝트입니다. `project.md`의 명세를 기준으로 Docker, Shhh child theme, 커스텀 플러그인 중심으로 구현합니다.

## Local Start

1. `.env.example`을 `.env`로 복사하고 비밀번호와 이메일 값을 수정합니다.
2. Docker Desktop의 WSL integration을 켭니다.
3. 컨테이너를 실행합니다.

```bash
docker compose up -d
```

4. WordPress를 초기화합니다.

```bash
docker compose run --rm --entrypoint bash wpcli /scripts/bootstrap.sh
```

5. 브라우저에서 `http://localhost:8080`을 엽니다.

## Services

- WordPress: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081`
- Mailpit: `http://localhost:8025`
