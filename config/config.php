<?php
// ─────────────────────────────────────────────────────────────
//  APEX — Configuración general
//
//  NO edites este archivo para poner contraseñas: usa uno de estos dos medios
//  (este archivo se publica en el repositorio; los otros dos no):
//    1. config/config.local.php  → copia de config.local.example.php con tus valores
//    2. Variables de entorno APEX_DB_HOST, APEX_DB_PORT, APEX_DB_USER, APEX_DB_PASS,
//       APEX_DB_NAME, APEX_DEBUG (útil en Docker y servidores)
//  Prioridad: config.local.php > variables de entorno > valores por defecto.
// ─────────────────────────────────────────────────────────────

if (is_file(__DIR__ . '/config.local.php')) require __DIR__ . '/config.local.php';

function apex_env(string $clave, string $defecto): string {
    $v = getenv('APEX_' . $clave);
    return $v === false || $v === '' ? $defecto : $v;
}

// Base de datos (en producción usa un usuario MySQL propio con contraseña, no root sin clave)
defined('DB_HOST') || define('DB_HOST', apex_env('DB_HOST', 'localhost'));
defined('DB_PORT') || define('DB_PORT', (int)apex_env('DB_PORT', '3306'));
defined('DB_USER') || define('DB_USER', apex_env('DB_USER', 'root'));
defined('DB_PASS') || define('DB_PASS', apex_env('DB_PASS', ''));
defined('DB_NAME') || define('DB_NAME', apex_env('DB_NAME', 'apex_db'));

// Marca
const APP_NAME    = 'APEX';
const APP_AUTHOR  = 'Ing. Antony Pérez';
const APP_VERSION = '1.0.0';

// Seguridad
defined('SESION_INACTIVIDAD_MIN') || define('SESION_INACTIVIDAD_MIN', 480);   // cierra la sesión tras 8 horas sin uso
defined('PASSWORD_MIN') || define('PASSWORD_MIN', 8);

// Producción: no mostrar errores en pantalla (quedan en logs/php_errors.log)
defined('APP_DEBUG') || define('APP_DEBUG', filter_var(apex_env('DEBUG', '0'), FILTER_VALIDATE_BOOLEAN));

date_default_timezone_set('America/Bogota');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/php_errors.log');
error_reporting(E_ALL);
if (!headers_sent()) header_remove('X-Powered-By');

if (!APP_DEBUG) {
    // Cualquier error no controlado: se registra en el log y el usuario ve un mensaje genérico
    set_exception_handler(function (Throwable $e) {
        error_log('[' . date('Y-m-d H:i:s') . '] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . APP_NAME . '</title></head>'
           . '<body style="font-family:system-ui,sans-serif;background:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">'
           . '<div style="background:#fff;padding:2rem 2.5rem;border-radius:16px;max-width:440px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.08)">'
           . '<h2 style="margin:0 0 .5rem;color:#0f172a">Ocurrió un error</h2>'
           . '<p style="color:#64748b">No se pudo completar la operación. Intenta de nuevo; si el problema continúa, contacta al administrador.</p>'
           . '<a href="dashboard.php" style="color:#3b82f6">Volver al inicio</a></div></body></html>';
        exit;
    });
}

// Pie de página con derechos de autor
function app_copyright(): string {
    return '© ' . date('Y') . ' ' . APP_NAME . ' by ' . APP_AUTHOR . '. Todos los derechos reservados.';
}

// Sesión segura (cookie solo HTTP, SameSite y Secure si el sitio está en HTTPS)
function app_session_start(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_name('APEXSESSID');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function app_pdo(): PDO {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // Fechas de MySQL (NOW, CURDATE) en hora de Colombia aunque el servidor esté en otra zona
    $pdo->exec("SET time_zone = '-05:00'");
    return $pdo;
}
