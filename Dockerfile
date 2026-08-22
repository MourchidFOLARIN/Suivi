FROM php:8.2-apache

# Extensions PHP nécessaires (PDO PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Active mod_rewrite pour Apache
RUN a2enmod rewrite headers

# Copie des fichiers de l'application
COPY . /var/www/html/

# Configuration Apache : autoriser .htaccess et les en-têtes CORS
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html/

# Port exposé par Render (dynamique via $PORT)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
