# Use the official PHP image with OpenSwoole
FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    ghostscript \
    poppler-utils \
    && rm -rf /var/lib/apt/lists/*

# === Add this line to copy your custom policy ===
COPY custom_policy.xml /etc/ImageMagick-6/policy.xml
# ===============================================

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip exif pcntl \
    && pecl install imagick openswoole \
    && docker-php-ext-enable imagick openswoole

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Create uploads directory
RUN mkdir -p /var/www/uploads \
    && chown -R www-data:www-data /var/www/uploads

# Copy application files
COPY . .

# Install PHP dependencies (if using composer)
RUN if [ -f "composer.json" ]; then composer install --no-dev --no-scripts --no-interaction; fi

# Expose port
EXPOSE 9501

# Health check
HEALTHCHECK --interval=30s --timeout=3s \
    CMD curl -f http://localhost:9501/health || exit 1

# Command to run the server
CMD ["php", "src/server.php"]