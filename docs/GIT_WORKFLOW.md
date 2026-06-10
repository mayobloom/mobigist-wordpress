# Git Workflow

This document describes how to manage code changes and production deployment for the Mobigist WordPress project.

## Code, Database, And Uploads

Treat code, database, and uploads as separate assets.

Code is managed by Git:

- child theme files
- custom plugins
- Dockerfile
- compose files
- scripts
- documentation

Database is not managed by Git:

- posts
- pages
- comments
- users
- WordPress settings
- plugin options
- taxonomy data

Uploads are not managed by Git:

- images
- attachments
- generated media files

Production database and production uploads are the source of truth for live content.

## Repository Locations

Use the same Git repository in both local development and the AWS server.

Typical structure:

```text
local development folder
        |
        | git push
        v
GitHub repository
        |
        | git pull
        v
AWS deployment folder
```

Example folders:

```text
Local: /home/hyun/wordpress
AWS:   /home/ubuntu/mobigist
```

The local folder is used for development. The AWS folder is used for deployment.

Do not edit production code directly on AWS except for emergency diagnosis. Normal changes should be committed locally, pushed to GitHub, merged, and then pulled on AWS.

## What Goes Into Git

Commit:

- `docker-compose.yml`
- `docker-compose.prod.yml`
- Dockerfiles
- custom plugins
- child theme files
- scripts
- docs
- `.env.example`
- `.env.production.example`

Do not commit:

- `.env`
- `.env.production`
- database dumps with real user data
- uploads
- cache files
- debug logs

## Before Making The Repository Public

Run a secret and privacy scan before publishing the repository.

Check the files that would be committed:

```bash
git status --short
git ls-files
```

Search for common secrets and personal data:

```bash
rg -n --hidden -S "password|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|smtp|ses|aws|BEGIN (RSA|OPENSSH|PRIVATE)" .
```

Expected public-safe values:

- placeholders such as `change-me`
- placeholders such as `replace-with-strong-password`
- example addresses such as `admin@example.com`

Values that must not be public:

- real `.env` values
- real AWS SES SMTP credentials
- real DB passwords
- real administrator passwords
- real backup files
- real user emails or user exports
- production uploads containing private images or documents
- private contact information that does not need to be in source code

If a secret was ever committed, do not rely on `.gitignore` or a later deletion. Rotate the secret and clean the Git history before making the repository public.

## Common Commit Types

Theme changes:

```text
Update post list thumbnail layout
Fix mobile sticky header spacing
```

Custom plugin features:

```text
Add member comment edit controls
Index internal links from post anchors
```

Operations changes:

```text
Add production compose override
Install EWWW optimizer binaries in WordPress image
```

Security fixes:

```text
Require nonce for comment like API
Restrict account page to authenticated users
```

Bug fixes:

```text
Fix graph node click navigation
Fix bold rendering on published posts
```

Docs:

```text
Document production deployment workflow
```

## Branch Strategy

Use a simple branch strategy.

Branch names should describe the work unit, not the date. Use lowercase words separated by hyphens.

Examples:

```text
feature/comment-likes
fix/mobile-header-spacing
ops/production-compose
```

`main`:

- stable branch
- should always be deployable
- AWS production usually pulls from this branch

`feature/*`:

- new features
- examples:
  - `feature/search-filters`
  - `feature/comment-likes`
  - `feature/graph-settings`

`fix/*`:

- normal bug fixes
- examples:
  - `fix/mobile-header-spacing`
  - `fix/rss-excerpt-setting`

`hotfix/*`:

- urgent production fixes
- examples:
  - `hotfix/login-error`
  - `hotfix/comment-critical-error`

`release/*`:

- optional
- useful when grouping multiple changes for final testing
- example:
  - `release/2026-06-10`

For a solo project, `main`, `feature/*`, `fix/*`, and `hotfix/*` are enough.

## GitHub Pull Request Flow

Prefer GitHub Pull Requests for changes that affect behavior, security, deployment, or database structure.

Local work:

```bash
git checkout -b feature/search-page
# Create and switch to a new feature branch.

# Edit files.
# Implement the change locally.

git add .
# Stage changed files for the next commit.

git commit -m "Add advanced search page"
# Save staged changes as a commit.

git push -u origin feature/search-page
# Push the branch to GitHub and track the remote branch.
```

GitHub:

1. Open a Pull Request.
2. Set `base` to `main`.
3. Set `compare` to the feature branch.
4. Review changed files.
5. Check tests if GitHub Actions are configured.
6. Merge the Pull Request.

After GitHub merges the PR, update local `main`:

```bash
git checkout main
# Switch to the stable branch.

git pull
# Fetch and apply the latest main from GitHub.
```

Deploy on AWS:

```bash
cd /home/ubuntu/mobigist
# Move to the AWS deployment folder.

git pull
# Pull the latest main branch from GitHub.

docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
# Rebuild and restart production containers using the base and production compose files.
```

## Local Merge Flow

For small changes, local merge is also acceptable.

```bash
git checkout -b fix/footer-copy
# Create and switch to a small fix branch.

# Edit files.
# Make the change.

git add .
# Stage changed files.

git commit -m "Fix footer copy"
# Commit the change.

git checkout main
# Switch back to main.

git merge fix/footer-copy
# Merge the fix branch into local main.

git push
# Push local main to GitHub.
```

Use Pull Requests for larger or riskier changes.

## Production Deployment Flow

Recommended deployment sequence:

```text
local feature/fix branch
-> commit
-> push branch
-> GitHub Pull Request
-> merge into main
-> AWS git pull
-> backup if needed
-> docker compose up -d --build
-> smoke test
```

Before production deploy:

- record the current Git commit
- back up production DB
- back up production uploads
- confirm `.env.production` is not committed

After production deploy:

- open homepage
- log in
- test comments
- test likes
- test email verification if relevant
- test Graph View if internal link code changed
- check container logs

## Database Changes

Do not manually copy a local database over production.

Production DB is the source of truth for live content. If a code change requires schema or option changes, implement the change as a migration inside the plugin or deployment process.

Good examples:

- plugin activation creates a custom table
- plugin version check runs `dbDelta()`
- missing options are created on `init`

Avoid:

- manually editing production DB without a backup
- replacing production DB with local DB
- relying on local WordPress options as production truth

## Emergency Hotfix

Use a hotfix branch for urgent production problems.

```bash
git checkout -b hotfix/login-error
# Create hotfix branch.

# Edit files.
# Fix the urgent issue.

git add .
# Stage the fix.

git commit -m "Fix login error handling"
# Commit the hotfix.

git push -u origin hotfix/login-error
# Push hotfix branch to GitHub.
```

Then create and merge a GitHub Pull Request into `main`, or merge locally if the issue is urgent and small.

Deploy:

```bash
cd /home/ubuntu/mobigist
# Move to production checkout.

git pull
# Pull fixed main.

docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
# Restart production containers.
```

If the hotfix changes the database or user-facing account behavior, back up before deploying.
