#!/bin/sh
# Espera a que la base de datos responda, crea o actualiza la estructura (setup.php es idempotente)
# y arranca Apache.
set -e
intentos=0
until php /var/www/html/setup.php; do
    intentos=$((intentos + 1))
    if [ "$intentos" -ge 30 ]; then
        echo "APEX: no se pudo conectar a la base de datos después de 30 intentos." >&2
        exit 1
    fi
    echo "APEX: esperando la base de datos ($intentos/30)..."
    sleep 2
done
chown -R www-data:www-data /var/www/html/logs
exec "$@"
