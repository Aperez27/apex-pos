#!/bin/bash
# Crea (o recrea) la copia de demostración de APEX usada para las capturas de los manuales:
# carpeta <htdocs>/apex_demo_manual + base apex_demo con datos ficticios. No toca la base real.
# Variables opcionales: HTDOCS, MYSQL_BIN, PHP_BIN, MYSQL_USER, MYSQL_PASS
# Al terminar los manuales:  rm -rf <htdocs>/apex_demo_manual  y  DROP DATABASE apex_demo
set -e
RAIZ=$(cd "$(dirname "$0")/../.." && pwd)
HTDOCS=${HTDOCS:-$(dirname "$RAIZ")}
D="$HTDOCS/apex_demo_manual"
MYSQL_BIN=${MYSQL_BIN:-$( [ -x /c/xampp/mysql/bin/mysql.exe ] && echo /c/xampp/mysql/bin/mysql.exe || echo mysql )}
PHP_BIN=${PHP_BIN:-$( [ -x /c/xampp/php/php.exe ] && echo /c/xampp/php/php.exe || echo php )}
MYSQL="$MYSQL_BIN -u${MYSQL_USER:-root} ${MYSQL_PASS:+-p$MYSQL_PASS} --default-character-set=utf8mb4"

rm -rf "$D"; mkdir -p "$D/logs" "$D/config"
cp -r "$RAIZ"/*.php "$RAIZ/.htaccess" "$RAIZ/includes" "$D/"
cp "$RAIZ/config/config.php" "$RAIZ/config/conexion.php" "$RAIZ/config/.htaccess" "$D/config/"
cp "$RAIZ/logs/.htaccess" "$D/logs/"
# La demo usa su propia base; nunca la configuración local real
cat > "$D/config/config.local.php" <<PHP
<?php
define('DB_NAME', 'apex_demo');
define('DB_USER', '${MYSQL_USER:-root}');
define('DB_PASS', '${MYSQL_PASS:-}');
PHP
$MYSQL -e "DROP DATABASE IF EXISTS apex_demo"
(cd "$D" && $PHP_BIN setup.php)
H=$($PHP_BIN -r 'echo password_hash("Demo2026!", PASSWORD_DEFAULT);')
sed "s|{HASH}|$H|g" "$(dirname "$0")/demo_datos.sql" | $MYSQL apex_demo
echo "Demo lista en http://localhost/apex_demo_manual (admin / laura, clave Demo2026!)"
