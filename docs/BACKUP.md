# Backup And Restore

Backups are required before production deployment, plugin updates, theme updates, WordPress updates, and database migrations.

## Backup Scope

Back up both:

- database
- uploaded media files

The repository itself should be protected by Git. Do not rely on file backups for source code history.

## Manual Backup

Run from the project root after loading `.env`.

```bash
set -a
source .env
set +a
./scripts/backup.sh
```

The script creates:

- `backups/db-YYYYMMDD-HHMMSS.sql`
- `backups/uploads-YYYYMMDD-HHMMSS.tar.gz`

## Off-Instance Storage

Production backups must not exist only on the Lightsail instance.

Recommended storage:

- S3 bucket with versioning enabled
- encrypted external storage
- Lightsail snapshot as an additional instance-level backup

Minimum policy:

- keep daily backups for at least 7 days
- keep weekly backups for at least 4 weeks
- keep a backup before each production release

## Restore Database

Stop write-heavy traffic before restoring.

```bash
docker compose exec -T db mariadb -uroot -p"$WORDPRESS_DB_ROOT_PASSWORD" "$WORDPRESS_DB_NAME" < backups/db-file.sql
```

If restoring into an empty database, confirm the target database exists first.

## Restore Uploads

Restore uploads into the mounted uploads directory.

```bash
tar -xzf backups/uploads-file.tar.gz
```

After restoring, confirm ownership and permissions match the WordPress container user.

```bash
docker compose exec -T wordpress sh -lc 'chown -R www-data:www-data /var/www/html/wp-content/uploads'
```

## Restore Rehearsal

Before launch, perform at least one restore rehearsal in a non-production environment.

Checklist:

- restore DB from the latest backup
- restore uploads archive
- start WordPress
- log in as administrator
- open posts with images
- open account pages
- create a test comment
- confirm Graph View loads
- confirm internal links still resolve

## Release Backup Procedure

Before every production release:

1. Record the current Git commit.
2. Run DB and uploads backup.
3. Copy backup files outside the instance.
4. Deploy.
5. Run smoke tests.
6. Keep the backup until the release is confirmed stable.

## Disaster Recovery Order

1. Provision a new Lightsail instance if needed.
2. Install Docker and Docker Compose.
3. Restore the repository at the last known good commit.
4. Restore `.env` from secure storage.
5. Restore DB.
6. Restore uploads.
7. Start containers.
8. Reconnect DNS or static IP.
9. Confirm HTTPS and email.
