# Operations Checklist

Use this checklist before launch and before each production release.

## Security

- Keep `WORDPRESS_DEBUG=0` in production.
- Enforce HTTPS for all public traffic.
- Keep WordPress, themes, and plugins updated.
- Review updates locally before production.
- Use strong administrator and database passwords.
- Avoid shared administrator accounts.
- Require 2FA for administrators and managers.
- Keep email verification enabled for all new members.
- Keep non-member comments disabled.
- Keep login rate limiting enabled.
- Review REST API permission callbacks when adding custom endpoints.
- Keep phpMyAdmin and Mailpit unavailable from the public internet.
- If using Nginx Proxy Manager, keep admin port `81` closed to the public internet.
- Access Nginx Proxy Manager through SSH tunneling, or restrict port `81` to the administrator's IP only.
- Remove unused inactive plugins unless they are intentionally retained.

## Account And Membership

- Confirm registration sends verification email.
- Confirm unverified users cannot log in.
- Confirm verified members can log in.
- Confirm members are redirected away from `wp-admin`.
- Confirm account deletion removes the user, comments, replies, post likes, and comment likes according to policy.
- Confirm administrator and manager profile edits go through the custom account page when intended.

## Comments And Likes

- Confirm anonymous users see the login prompt in the comment area.
- Confirm anonymous users cannot submit comments.
- Confirm logged-in users can create comments.
- Confirm users can edit and delete their own comments.
- Confirm replies are limited to one level.
- Confirm comment pagination is enabled.
- Confirm post likes require login.
- Confirm comment likes require login if comment likes are enabled.

## Content

- Confirm categories shown in the header are intentional.
- Confirm category header order is correct.
- Confirm child categories render only under their parent category pages.
- Confirm tags render as hashtag pills on post pages.
- Confirm featured images render in post lists but not beside the single post title.
- Confirm RSS uses excerpts, not full text.

## Editor

- Confirm editor font matches the site font.
- Confirm bold text renders in the editor and frontend.
- Confirm Math block tools are visible.
- Confirm Extended Code Block syntax highlighting works in the editor and frontend.
- Confirm code copy button works in published posts.

## Graph And Internal Links

- Confirm WordPress editor links are used as the internal link source.
- Confirm internal links are indexed on post save.
- Confirm heading links use URL fragments.
- Confirm heading target highlight appears on navigation.
- Confirm full Graph View loads.
- Confirm post-level partial graphs load.
- Confirm graph nodes and labels navigate to posts.
- Confirm non-public posts are not exposed to unauthorized users.

## Performance

- Confirm WP Super Cache is enabled.
- Confirm account, login, registration, password reset, and 2FA pages are not incorrectly cached.
- Confirm EWWW Image Optimizer detects:
  - `gifsicle`
  - `jpegtran`
  - `optipng`
  - `pngquant`
  - `cwebp`
- Run EWWW bulk optimization for existing media after production migration.
- Keep Graph View API responses scoped by permission and graph depth.
- Parse internal links on post save rather than on every page view.
- Consider Redis object caching only after traffic or query load justifies it.

## SEO

- Confirm Slim SEO is active.
- Confirm RSS warning is resolved by excerpt feeds.
- Confirm public posts have correct canonical URLs.
- Confirm production URLs do not contain `localhost`.
- Confirm robots settings do not block the public site.
- Submit sitemap to search consoles after the production domain is final.

## Email

- Confirm AWS SES SMTP credentials are configured.
- Confirm sender domain is verified in SES.
- Confirm SPF, DKIM, and DMARC DNS records are configured.
- Test:
  - registration verification email
  - resend verification email
  - password reset email
  - email change confirmation
  - 2FA email fallback

## Release

1. Review code changes.
2. Run local smoke tests.
3. Back up DB and uploads.
4. Copy backups outside the instance.
5. Deploy code.
6. Rebuild containers if Dockerfiles changed.
7. Run WordPress database updates if prompted.
8. Clear cache.
9. Re-run smoke tests.
10. Check email verification, login, comments, likes, and Graph View.

## Incident Response

If the site is unavailable:

1. Check reverse proxy container logs.
2. Check WordPress container logs.
3. Check DB container health.
4. Confirm disk space.
5. Confirm DNS points to the correct static IP.
6. Confirm certificates are valid.
7. Roll back to the previous Git commit if the issue started after a deploy.
8. Restore DB/uploads only if content or schema corruption occurred.
