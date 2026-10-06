<?php
// Configuración local de APEX para ESTE equipo o servidor.
// 1. Copia este archivo como  config/config.local.php
// 2. Ajusta los valores. config.local.php NO se sube al repositorio (está en .gitignore).

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_USER', 'apex');                      // usuario MySQL propio del sistema (no root)
define('DB_PASS', 'cambia-esta-clave-larga');   // contraseña de ese usuario
define('DB_NAME', 'apex_db');

// Opcional
// define('APP_DEBUG', true);                   // solo para diagnosticar: muestra los errores en pantalla
// define('SESION_INACTIVIDAD_MIN', 480);       // minutos sin uso antes de cerrar la sesión
// define('PASSWORD_MIN', 8);                   // longitud mínima de contraseñas
