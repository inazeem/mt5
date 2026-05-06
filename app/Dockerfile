FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    zip \
    unzip \
    sqlite \
    sqlite-dev \
    nodejs \
    npm \
    supervisor \
    nginx

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite gd opcache pcntl

# Install pnpm
RUN npm install -g pnpm

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy package files and install JS deps
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

# Copy remaining app files
COPY . .

# Build frontend assets
RUN pnpm run build

# Run composer post-install scripts
RUN composer run-script post-autoload-dump

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

# Create SQLite database directory
RUN mkdir -p /var/www/html/database \
    && touch /var/www/html/database/database.sqlite \
    && chown -R www-data:www-data /var/www/html/database

# Nginx config
RUN echo 'server { \n\
    listen 80; \n\
    root /var/www/html/public; \n\
    index index.php; \n\
    location / { try_files $uri $uri/ /index.php?$query_string; } \n\
    location ~ \.php$ { \n\
        fastcgi_pass 127.0.0.1:9000; \n\
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \n\
        include fastcgi_params; \n\
    } \n\
}' > /etc/nginx/http.d/default.conf

# Supervisor config to run nginx + php-fpm
RUN echo '[supervisord] \n\
nodaemon=true \n\
[program:php-fpm] \n\
command=php-fpm \n\
autostart=true \n\
autorestart=true \n\
[program:nginx] \n\
command=nginx -g "daemon off;" \n\
autostart=true \n\
autorestart=true' > /etc/supervisord.conf

EXPOSE 80

CMD ["sh", "-c", "echo \"Runtime DB_HOST=${DB_HOST}\" && rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php && php artisan optimize:clear && php artisan migrate --force && php artisan config:cache && php artisan route:cache && supervisord -c /etc/supervisord.conf"]
