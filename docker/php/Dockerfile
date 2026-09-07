# 8.4 rather than 8.3: composer.lock resolves to Symfony 8, which requires
# PHP >= 8.4.1.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        bcmath \
        intl \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize

# The project directory is bind mounted from the host, so the container must
# write storage/ and bootstrap/cache as the host's own user. Override with
#   docker compose build --build-arg UID=$(id -u) --build-arg GID=$(id -g)
# if your account is not 1000:1000.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" -S app 2>/dev/null || true \
    && adduser -u "${UID}" -G app -S -D app 2>/dev/null || true \
    && sed -i "s/^user = .*/user = ${UID}/; s/^group = .*/group = ${GID}/" \
        /usr/local/etc/php-fpm.d/www.conf \
    && chown -R "${UID}:${GID}" storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]
