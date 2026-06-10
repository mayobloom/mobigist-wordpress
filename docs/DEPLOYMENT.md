# Deployment

This document describes the production deployment flow for the Mobigist WordPress site.

For Git branch, Pull Request, and deployment version-control rules, see `docs/GIT_WORKFLOW.md`.

## Local Baseline

1. Copy `.env.example` to `.env`.
2. Update local passwords, admin email, and service ports.
3. Start services.

```bash
docker compose up -d
```

4. Bootstrap WordPress if the database is empty.

```bash
docker compose run --rm --entrypoint bash wpcli /scripts/bootstrap.sh
```

5. Run smoke tests.

```bash
docker compose run --rm --entrypoint bash wpcli /scripts/test.sh
```

## Production Target

The intended production target is AWS Lightsail with:

- Docker Engine and Docker Compose
- Static Lightsail IP
- Route 53 DNS record pointing to the static IP
- HTTPS termination through a reverse proxy
- AWS SES SMTP for outbound mail
- Backups stored outside the instance

## Production Environment

Create a production `.env` from `.env.production.example`, not from the local `.env`.

Required changes:

- `WORDPRESS_URL` must be the public HTTPS domain, for example `https://mobigist.com`.
- `WORDPRESS_DEBUG` must be `0`.
- `WORDPRESS_ENV` must be `production`.
- DB passwords and administrator password must be newly generated strong values.
- AWS SES SMTP values must be real production credentials.
- Local-only services such as phpMyAdmin and Mailpit must not be publicly exposed.

After changing the public domain, confirm WordPress stores the production URL:

```bash
docker compose exec -T wordpress php -r 'require "/var/www/html/wp-load.php"; echo get_option("home") . PHP_EOL . get_option("siteurl") . PHP_EOL;'
```

Both values must be the HTTPS production domain.

## HTTPS Reverse Proxy

HTTPS can be implemented with Nginx. There are two practical options.

### Option A: Plain Nginx Container

Use a dedicated `nginx` container that exposes ports `80` and `443`, terminates TLS, and proxies traffic to the internal WordPress container on port `80`.

Recommended when:

- You want explicit config files in Git.
- You are comfortable managing Nginx server blocks and certificates.
- You want minimal moving parts.

Typical flow:

1. DNS points `mobigist.com` to the Lightsail static IP.
2. Nginx listens on `80` and `443`.
3. Certbot or an ACME sidecar issues Let's Encrypt certificates.
4. Nginx proxies requests to `http://wordpress:80`.
5. The WordPress container is not exposed directly to the public internet.

Important headers:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Real-IP $remote_addr;
proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto https;
```

### Option B: Nginx Proxy Manager

Nginx Proxy Manager is also viable in Docker. It provides a web UI for managing proxy hosts and Let's Encrypt certificates.

Recommended when:

- You prefer managing domains, certificates, and redirects through a web UI.
- You expect to add more services later.
- You want easier certificate renewal management.

Production cautions:

- Do not expose the Nginx Proxy Manager admin UI publicly without strong access controls.
- Change the default admin credentials immediately.
- Restrict the admin UI by firewall, VPN, or private network when possible.
- Only ports `80` and `443` should be public for normal site traffic.
- Prefer keeping the admin UI port `81` closed to the public internet.
- Access the admin UI through an SSH tunnel when possible:

```bash
ssh -L 8181:localhost:81 ubuntu@SERVER_IP
```

Then open:

```text
http://localhost:8181
```

- If SSH tunneling is not practical, allow TCP `81` only from the administrator's current IP address in the Lightsail firewall. Do not allow `0.0.0.0/0` for port `81`.

For this project, Nginx Proxy Manager is the easier operational choice on a single Lightsail instance. Plain Nginx is cleaner if the deployment should be fully config-as-code.

## Production Compose Separation

Production should use a separate compose file: `docker-compose.prod.yml`.

The local compose file includes development conveniences:

- phpMyAdmin
- Mailpit
- local HTTP port mappings
- local debug defaults

The production compose should:

- expose only the reverse proxy on `80` and `443`
- keep WordPress, DB, and internal tools on a private Docker network
- remove Mailpit
- remove public phpMyAdmin exposure
- use production restart policies
- mount only required volumes
- avoid debug-oriented settings

Compose files are infrastructure configuration and should be committed to Git:

- `docker-compose.yml`
- `docker-compose.prod.yml`

Real environment files contain secrets and must not be committed:

- `.env`
- `.env.production`

Example environment templates should be committed:

- `.env.example`
- `.env.production.example`

The recommended `.gitignore` rule is:

```gitignore
.env
.env.*
!.env.example
!.env.production.example
```

This keeps real local/production secrets out of Git while preserving example templates.

Example command:

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build
```

This project uses a standalone production compose file rather than a compose override. That keeps local-only services such as phpMyAdmin and Mailpit out of the production service graph entirely.

The production compose exposes only:

- `80` and `443` for public web traffic through Nginx Proxy Manager
- `127.0.0.1:81` for the Nginx Proxy Manager admin UI, reachable through an SSH tunnel

WordPress itself is not published directly to the internet in production. In Nginx Proxy Manager, create a Proxy Host that forwards:

```text
Scheme: http
Forward Hostname / IP: wordpress
Forward Port: 80
```

## Lightsail Deployment Flow

1. Create a Lightsail instance.
2. Attach a static IP.
3. Install Docker Engine and Docker Compose.
4. Copy this repository to the instance.
5. Create the production `.env`.
6. Configure Route 53 DNS to the static IP.
7. Start the reverse proxy.
   - Public firewall rules should allow `80` and `443`.
   - Nginx Proxy Manager admin port `81` should stay private and be accessed through SSH tunneling.
8. Start WordPress, DB, and Nginx Proxy Manager with the production compose configuration.
9. Confirm HTTPS works.
10. Confirm WordPress `home` and `siteurl` use the HTTPS domain.
11. Configure AWS SES SMTP values.
12. Send test emails for:
    - registration verification
    - password reset
    - 2FA email fallback
13. Run smoke tests.
14. Create the first production backup.

## Post-Deployment Checks

- Open the homepage over HTTPS.
- Log in through `/login/`.
- Confirm `/wp-admin/` requires authentication.
- Confirm administrator and manager users require 2FA.
- Register a test member and complete email verification.
- Create a private test post and confirm unauthorized users cannot access it.
- Create a public post with tags, featured image, internal links, math, and code block.
- Confirm Graph View renders and internal links are indexed.
- Confirm comments require login.
- Confirm likes require login.
- Confirm RSS uses excerpts, not full text.
- Confirm EWWW Image Optimizer detects image tools.
- Confirm WP Super Cache is enabled and does not cache account pages.

## Rollback

Before deploying code or plugin updates:

1. Run a DB and uploads backup.
2. Record the current Git commit.
3. Deploy the update.
4. If the site fails, restore the previous commit and restore the DB/uploads backup if schema or content changed.

Keep at least one recent backup outside the Lightsail instance.
