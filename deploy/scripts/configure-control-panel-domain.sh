#!/usr/bin/env bash

set -Eeuo pipefail

readonly DOMAIN="admin.lorapms.com"
readonly APP_ROOT="/var/www/villamucho"
readonly TEMPLATE="$APP_ROOT/deploy/nginx/admin.lorapms.com.conf"
readonly SITE_AVAILABLE="/etc/nginx/sites-available/$DOMAIN"
readonly SITE_ENABLED="/etc/nginx/sites-enabled/$DOMAIN"

if [[ ! -f "$TEMPLATE" ]]; then
    echo "Missing Nginx template: $TEMPLATE" >&2
    exit 1
fi

if [[ -n "${PHP_FPM_SOCKET:-}" ]]; then
    php_fpm_socket="$PHP_FPM_SOCKET"
else
    php_fpm_socket="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print | sort -V | tail -n 1)"
fi

if [[ ! "$php_fpm_socket" =~ ^/run/php/php[0-9]+([.][0-9]+)?-fpm[.]sock$ ]]; then
    echo "Invalid PHP-FPM socket path: $php_fpm_socket" >&2
    exit 1
fi

if [[ -z "${PHP_FPM_SOCKET:-}" && ! -S "$php_fpm_socket" ]]; then
    echo "No live PHP-FPM socket found at $php_fpm_socket." >&2
    exit 1
fi

if [[ ! -f "$SITE_AVAILABLE" ]]; then
    temporary_site="$(mktemp)"
    trap 'rm -f "$temporary_site"' EXIT
    sed "s|__PHP_FPM_SOCKET__|$php_fpm_socket|g" "$TEMPLATE" > "$temporary_site"
    install -m 0644 "$temporary_site" "$SITE_AVAILABLE"
fi

ln -sfn "$SITE_AVAILABLE" "$SITE_ENABLED"
nginx -t
systemctl reload nginx

if ! command -v certbot >/dev/null 2>&1; then
    echo "Certbot is required to provision SSL for $DOMAIN." >&2
    exit 1
fi

certbot --nginx \
    --non-interactive \
    --agree-tos \
    --register-unsafely-without-email \
    --redirect \
    --keep-until-expiring \
    -d "$DOMAIN"

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$APP_ROOT/.env"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$APP_ROOT/.env"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$APP_ROOT/.env"
    fi
}

set_env_value "LORA_CONTROL_PANEL_URL" "https://$DOMAIN"
set_env_value "LORA_CONTROL_PANEL_HOSTS" "$DOMAIN"
set_env_value "LORA_DEDICATED_CONTROL_PANEL_HOSTS" "$DOMAIN"

nginx -t
systemctl reload nginx

echo "Lora Control Panel configured at https://$DOMAIN/super-admin"
