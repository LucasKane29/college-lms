# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000
ARG AMQP_VERSION=2.2.0
ARG REDIS_VERSION=6.3.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip zip procps libfcgi-bin \
        libicu-dev libzip-dev librabbitmq-dev libssl-dev \
        libonig-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql intl zip opcache pcntl bcmath sockets \
    && pecl install "amqp-${AMQP_VERSION}" "redis-${REDIS_VERSION}" \
    && docker-php-ext-enable amqp redis \
    && php -r 'foreach (["pdo_mysql", "intl", "zip", "Zend OPcache", "pcntl", "amqp", "redis", "mbstring", "dom", "xml", "curl"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } }' \
    && groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g www-data www-data \
    && mkdir -p /var/www/html /tmp/composer \
    && chown www-data:www-data /var/www/html /tmp/composer \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY php.ini /usr/local/etc/php/conf.d/zz-college.ini
COPY zz-college.conf /usr/local/etc/php-fpm.d/zz-college.conf
COPY --chmod=755 fpm-healthcheck /usr/local/bin/fpm-healthcheck

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
