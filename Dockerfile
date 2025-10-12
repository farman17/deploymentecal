# Dockerfile — PHP 8.2 FPM + ekstensi yang dibutuhkan
FROM php:8.2-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      git \
      unzip \
      curl \
      pkg-config \
      libonig-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libfreetype6-dev \
      libzip-dev \
      libxml2-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd mysqli pdo pdo_mysql zip mbstring xml; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
