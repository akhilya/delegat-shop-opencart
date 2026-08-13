#!/bin/sh
set -eu

SITE_URL="${SITE_URL:-http://localhost:8089/}"
case "$SITE_URL" in
  */) ;;
  *) SITE_URL="${SITE_URL}/" ;;
esac
export SITE_URL

echo "Waiting for MariaDB..."
n=0
until php -r '
$m = @new mysqli(getenv("DB_HOSTNAME"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), getenv("DB_DATABASE"), (int)getenv("DB_PORT"));
exit(($m && !$m->connect_error) ? 0 : 1);
'; do
  n=$((n + 1))
  if [ "$n" -gt 60 ]; then
    echo "Timeout waiting for MariaDB"
    exit 1
  fi
  sleep 2
done

if [ ! -f /var/www/html/index.php ]; then
  echo "Seeding OpenCart files..."
  cp -a /usr/src/opencart/. /var/www/html/
fi

if [ -f /var/www/html/.htaccess.txt ] && [ ! -f /var/www/html/.htaccess ]; then
  cp /var/www/html/.htaccess.txt /var/www/html/.htaccess
fi
if [ -f /var/www/html/.htaccess ] && ! grep -q 'HTTP_AUTHORIZATION' /var/www/html/.htaccess; then
  printf '\nRewriteEngine On\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%%{HTTP:Authorization}]\n' >> /var/www/html/.htaccess
fi

prefix="${DB_PREFIX:-oc_}"
installed=0
if php -r '
$m = new mysqli(getenv("DB_HOSTNAME"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), getenv("DB_DATABASE"), (int)getenv("DB_PORT"));
if ($m->connect_error) { exit(1); }
$prefix = getenv("DB_PREFIX") ?: "oc_";
$res = $m->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = \"" . $m->real_escape_string($prefix . "setting") . "\" LIMIT 1");
exit(($res && $res->num_rows) ? 0 : 1);
'; then
  installed=1
fi

if [ "$installed" -eq 0 ]; then
  echo "Running OpenCart CLI install..."
  cp -f /var/www/html/config-dist.php /var/www/html/config.php
  cp -f /var/www/html/admin/config-dist.php /var/www/html/admin/config.php
  php /var/www/html/install/cli_install.php install \
    --username "${OPENCART_USERNAME}" \
    --password "${OPENCART_PASSWORD}" \
    --email "${OPENCART_EMAIL}" \
    --http_server "${SITE_URL}" \
    --db_driver mysqli \
    --db_hostname "${DB_HOSTNAME}" \
    --db_username "${DB_USERNAME}" \
    --db_password "${DB_PASSWORD}" \
    --db_database "${DB_DATABASE}" \
    --db_port "${DB_PORT}" \
    --db_prefix "${prefix}"
  rm -rf /var/www/html/install
fi

echo "Installing Delegat Bridge files..."
mkdir -p /var/www/html/extension/delegat_bridge
cp -a /usr/src/delegat_bridge/. /var/www/html/extension/delegat_bridge/

php /usr/local/bin/rewrite-site-url.php
php /usr/local/bin/enable-bridge.php
rm -rf /var/www/html/system/storage/cache/* || true
chown -R www-data:www-data /var/www/html

echo "READY"
echo "Admin: ${SITE_URL}admin  (${OPENCART_USERNAME} / ${OPENCART_PASSWORD})"
echo "Bridge URL: ${SITE_URL}index.php?route=extension/delegat_bridge/api/delegat"
echo "API key: ${BRIDGE_API_KEY}"

exec "$@"
