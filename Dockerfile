# Dockerfile
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Enable Apache modules
RUN a2enmod rewrite

# Set Apache configuration
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Create directory structure
RUN mkdir -p /var/www/html/posters/movies \
    /var/www/html/posters/tv-shows \
    /var/www/html/posters/tv-seasons \
    /var/www/html/posters/collections \
    /var/www/html/assets

# Copy the application files
COPY index.php /var/www/html/

# Copy assets directory
COPY assets/ /var/www/html/assets/

# Copy and set up entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure proper permissions
RUN chown -R www-data:www-data /var/www/html && \
    find /var/www/html -type d -exec chmod 755 {} \; && \
    find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
