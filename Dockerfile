FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        sqlite3 \
        libsqlite3-dev \
    && docker-php-ext-install sqlite3 pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

# SQLite crée survey.db, survey.db-wal et survey.db-shm dans le dossier de l'application.
# Il faut donc que le user Apache puisse écrire dans ce répertoire.
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
