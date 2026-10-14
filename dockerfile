FROM php:8.2-apache

# Instalar extensiones de PHP necesarias (Curl para Guzzle, MBString, GD para imágenes)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer (el manejador de dependencias de PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar el directorio de trabajo dentro del contenedor
WORKDIR /var/www/html

# Copiar los archivos del proyecto
COPY . /var/www/html

# Instalar dependencias de Composer sin scripts de desarrollo
RUN composer install --no-dev --optimize-autoloader

# Dar permisos correctos a las carpetas de almacenamiento (storage)
RUN mkdir -p storage/output storage/uploads storage/templates \
    && chmod -R 777 storage

# Habilitar mod_rewrite de Apache para las rutas limpias de Fat-Free Framework
RUN a2enmod rewrite

# Cambiar la ruta por defecto de Apache hacia la carpeta pública de tu proyecto 
# (Si tu index.php está en la raíz, cambia esto a /var/www/html)
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Render asigna dinámicamente un puerto, ajustamos Apache para escucharlo
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 10000
