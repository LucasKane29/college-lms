#!/usr/bin/env bash
# Run as the Linux repository owner (for example deploy), without sudo.
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ -e .env ]]; then
    echo 'The root .env already exists; it was not changed.'
    exit 0
fi
command -v openssl >/dev/null || { echo 'OpenSSL is required.' >&2; exit 1; }
[[ -f .env.example ]] || { echo '.env.example is missing.' >&2; exit 1; }
[[ "$(id -u)" != 0 ]] || { echo 'Run as the repository owner, without sudo.' >&2; exit 1; }
app_secret=$(openssl rand -hex 32)
mysql_password=$(openssl rand -hex 32)
mysql_root_password=$(openssl rand -hex 32)
redis_password=$(openssl rand -hex 32)
rabbitmq_password=$(openssl rand -hex 32)
umask 077
set -o noclobber
sed \
    -e "s/^APP_SECRET=.*/APP_SECRET=${app_secret}/" \
    -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=${mysql_password}/" \
    -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=${mysql_root_password}/" \
    -e "s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=${redis_password}/" \
    -e "s/^RABBITMQ_PASSWORD=.*/RABBITMQ_PASSWORD=${rabbitmq_password}/" \
    -e "s/^APP_UID=.*/APP_UID=$(id -u)/" \
    -e "s/^APP_GID=.*/APP_GID=$(id -g)/" \
    .env.example > .env
echo 'Created the root .env with unique secrets and the current user UID/GID.'
