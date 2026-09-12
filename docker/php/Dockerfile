# PHP 8.4 + Composer + Node 20 для сборки и запуска Laravel.
# Контекст сборки — каталог ./app (чистый экспорт backend/).
FROM php:8.4-fpm

# Системные зависимости: MySQL-клиент для healthcheck, git/unzip для composer, Node для сборки SPA.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates \
    default-mysql-client \
    libpng-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath zip \
    && rm -rf /var/lib/apt/lists/*

# Node.js 20 (сборка фронтенда через npm run build).
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Сначала зависимости PHP (лучше кэширование слоёв), затем остальной код.
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

COPY ./ ./

# Сборка SPA: public/build генерируется внутри образа.
RUN npm ci && npm run build

RUN chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
