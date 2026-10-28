FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY *.php ./
COPY style.css azu-logo.png ./
COPY database /opt/azu_pimsi/database
COPY db_connection.php /opt/azu_pimsi/db_connection.php

ENV PORT=10000

RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's#<VirtualHost \*:80>#<VirtualHost *:${PORT}>#' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["sh", "-c", "php /opt/azu_pimsi/database/init.php && exec apache2-foreground"]
