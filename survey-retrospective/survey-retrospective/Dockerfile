# Image minimale : PHP 8.3 + Apache, sans aucune extension supplémentaire.
# La "base de données" est le fichier data.json à la racine de l'application.
FROM php:8.3-apache

WORKDIR /var/www/html

# Fichiers de l'application (voir .dockerignore pour ce qui est exclu : .git, tests, etc.)
COPY --chown=www-data:www-data index.html results.html admin.html api.php data.json ./

# Apache tourne sous www-data : il doit pouvoir écrire data.json, le fichier de verrou
# et le fichier temporaire utilisé pour l'écriture atomique, donc le dossier lui-même.
RUN chown www-data:www-data /var/www/html \
    && chmod 775 /var/www/html \
    && chmod 664 /var/www/html/data.json

# Ne jamais afficher les erreurs PHP en HTML (l'API répond toujours en JSON).
RUN printf 'display_errors=Off\nlog_errors=On\nerror_log=/dev/stderr\n' > /usr/local/etc/php/conf.d/app.ini

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit((json_decode((string)@file_get_contents("http://localhost/api.php?action=health"), true)["success"] ?? false) ? 0 : 1);'
