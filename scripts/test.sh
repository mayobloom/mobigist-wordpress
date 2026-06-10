#!/usr/bin/env bash
set -euo pipefail

wp --allow-root eval 'echo "WordPress bootstrap OK\n";'
php -l /var/www/html/wp-content/plugins/pkb-core/pkb-core.php
php -l /var/www/html/wp-content/themes/pkb-shhh-child/functions.php
wp --allow-root plugin list
wp --allow-root theme list
wp --allow-root option get comment_registration
wp --allow-root option get thread_comments

