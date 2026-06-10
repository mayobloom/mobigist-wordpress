#!/usr/bin/env bash
set -euo pipefail

timestamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p backups

docker compose exec -T db mariadb-dump \
  -uroot \
  -p"${WORDPRESS_DB_ROOT_PASSWORD}" \
  "${WORDPRESS_DB_NAME}" > "backups/db-${timestamp}.sql"

tar -czf "backups/uploads-${timestamp}.tar.gz" wordpress/wp-content/uploads

echo "Created backups/db-${timestamp}.sql"
echo "Created backups/uploads-${timestamp}.tar.gz"

