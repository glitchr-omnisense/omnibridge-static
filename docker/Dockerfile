# PHP 8.2 (the lowest this package declares) with Composer 2 and nothing else: the exporter asks for no extension.
FROM php:8.2-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/* \
    && git config --system --add safe.directory '*'
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1 COMPOSER_HOME=/tmp/composer
WORKDIR /package
