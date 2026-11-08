FROM php:8.2-apache

# 1. Instalar paquetes del sistema y extensiones PHP para MySQL
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# 2. Apuntar Apache a la carpeta /public de Laravel
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# 3. Incluir Composer oficial
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 4. Copiar el proyecto al contenedor
WORKDIR /var/www/html
COPY . .

# 5. Preparar .env (si no existe), carpetas de cache/storage, instalar Composer, generar APP_KEY y permisos
RUN if [ ! -f .env ]; then cp .env.example .env && sed -i 's|^DB_HOST=.*|DB_HOST=db|' .env && sed -i 's|^DB_PASSWORD=.*|DB_PASSWORD=1234|' .env; fi \
    && mkdir -p bootstrap/cache storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chmod -R 775 storage bootstrap/cache \
    && composer install --no-interaction --optimize-autoloader \
    && php artisan key:generate \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80
