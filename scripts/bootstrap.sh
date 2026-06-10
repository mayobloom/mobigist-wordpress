#!/usr/bin/env bash
set -euo pipefail

wp core install \
  --url="${WORDPRESS_URL}" \
  --title="${WORDPRESS_TITLE}" \
  --admin_user="${WORDPRESS_ADMIN_USER}" \
  --admin_password="${WORDPRESS_ADMIN_PASSWORD}" \
  --admin_email="${WORDPRESS_ADMIN_EMAIL}" \
  --skip-email

wp theme install shhh --activate
wp theme activate pkb-shhh-child
wp plugin activate pkb-core
wp rewrite structure '/%category%/%postname%/' --hard
wp option update default_comment_status open
wp option update comment_registration 1
wp option update thread_comments 1
wp option update users_can_register 0
wp pkb setup

