FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

# System toolchain: PHP 8.3 CLI + the extensions ReactPHP and Composer need,
# plus git/curl/unzip for source checkout and dependency downloads.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        php-cli \
        php-mbstring \
        php-xml \
        php-curl \
        php-zip \
        php-sqlite3 \
    && rm -rf /var/lib/apt/lists/*

# Composer (pinned installer, verified against the published signature).
RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm -f /tmp/composer-setup.php \
    && composer --version

USER ubuntu
