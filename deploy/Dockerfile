# Pin base image ke digest agar build reproducible
FROM php:8.3-apache@sha256:a800bbd2c0aa322702259ed475556b2cbc311076a8d3c4bba9bfe96838745472

# Build dependencies untuk ekstensi native (versi di-pin sesuai hadolint DL3008)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev=1.6.48-1+deb13u6 \
        libjpeg62-turbo-dev=1:2.1.5-4 \
        libwebp-dev=1.5.0-0.1 \
        libfreetype-dev=2.13.3+dfsg-1+deb13u1 \
        zlib1g-dev=1:1.3.dfsg+really1.3.1-1+deb13u1; \
    rm -rf /var/lib/apt/lists/*

# Driver MySQL (pdo_mysql/mysqli) + gd (thumbnail & cek foto) + opcache
RUN set -eux; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd opcache; \
    a2enmod rewrite headers; \
    { \
      echo 'upload_max_filesize=25M'; \
      echo 'post_max_size=30M'; \
      echo 'memory_limit=256M'; \
      echo 'date.timezone=Asia/Jakarta'; \
    } > /usr/local/etc/php/conf.d/app.ini

# DocumentRoot default Apache = /var/www/html
WORKDIR /var/www/html
