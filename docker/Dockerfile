# ==========================================
# OmniPublish Backend Base Container
# ==========================================
FROM php:8.3-cli-alpine AS base

# Install system dependencies & libraries
RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    postgresql-dev \
    linux-headers \
    ffmpeg \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql bcmath pcntl

# Install Redis extension
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY backend/ /var/www/html/

# Set file permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# ==========================================
# Target: API Container
# ==========================================
FROM base AS api
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=5s --start-period=5s --retries=3 \
  CMD curl -f http://127.0.0.1:8000/health/live || exit 1
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ==========================================
# Target: Publishing Worker Container
# ==========================================
FROM base AS publishing-worker
CMD ["php", "artisan", "queue:work", "redis", "--queue=publishing", "--tries=5", "--timeout=300", "--sleep=3", "--memory=512"]

# ==========================================
# Target: Media Processing Worker Container (FFmpeg)
# ==========================================
FROM base AS media-worker
RUN ffmpeg -version && ffprobe -version
CMD ["php", "artisan", "queue:work", "redis", "--queue=media", "--tries=3", "--timeout=600", "--sleep=3", "--memory=1024"]

# ==========================================
# Target: Default Worker Container
# ==========================================
FROM base AS default-worker
CMD ["php", "artisan", "queue:work", "redis", "--queue=default", "--tries=3", "--timeout=120", "--sleep=3", "--memory=256"]

# ==========================================
# Target: Unified Worker Container (Local Dev)
# ==========================================
FROM base AS worker
RUN ffmpeg -version && ffprobe -version
CMD ["php", "artisan", "queue:work", "redis", "--queue=publishing,media,default", "--tries=3", "--timeout=600"]

# ==========================================
# Target: Scheduler Container
# ==========================================
FROM base AS scheduler
CMD ["php", "artisan", "schedule:work"]
