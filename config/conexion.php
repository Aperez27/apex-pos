<?php
require_once __DIR__ . '/config.php';
app_session_start();
// Todas las páginas que incluyen este archivo requieren sesión iniciada
if (!isset($_SESSION['usuario'])) { header("Location: index.php"); exit; }
if (isset($_SESSION['ultimo_uso']) && time() - $_SESSION['ultimo_uso'] > SESION_INACTIVIDAD_MIN * 60) {
    $_SESSION = []; session_destroy();
    header("Location: index.php?expirada=1"); exit;
}
$_SESSION['ultimo_uso'] = time();
$rol = $_SESSION['rol'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
function csrf_verify(): void {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        http_response_code(403);
        die('Token de seguridad inválido. Recarga la página e intenta nuevamente.');
    }
}
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
// Mensaje flash: se guarda en sesión, se redirige y se muestra una sola vez
function flash_redirect(string $url, string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
    header("Location: {$url}"); exit;
}
function flash_get(): array {
    $f = $_SESSION['flash'] ?? ['msg' => '', 'type' => 'success'];
    unset($_SESSION['flash']);
    return [$f['msg'], $f['type']];
}
// Formas de pago (valor guardado en ventas.estado => etiqueta visible)
const FORMAS_PAGO = [
    'Efectivo' => 'Efectivo', 'Transferencia' => 'Transferencia', 'Tarjeta' => 'Tarjeta',
    'Terceros' => 'Terceros', 'Credito Interno' => 'Crédito Interno', 'Mixto' => 'Pago Mixto',
];
const METODOS_CAJA    = ['Efectivo', 'Transferencia', 'Tarjeta'];   // entran a caja al vender
const METODOS_CARTERA = ['Terceros', 'Credito Interno'];             // quedan como saldo en Cartera
const ENTIDADES_TERCEROS = ['Addi', 'Sistecrédito'];
function forma_pago_label(?string $estado): string {
    $legado = ['Fiado' => 'Fiado', 'Credito RP' => 'Crédito RP', 'Vendido' => 'Vendido', 'Devolucion' => 'Devolución'];
    return FORMAS_PAGO[$estado] ?? $legado[$estado] ?? (string)$estado;
}

// Colores de producto: nombre => muestra hex (un color fuera de la lista se muestra en gris)
const COLORES = [
    'Negro' => '#1f2937', 'Blanco' => '#f8fafc', 'Plata' => '#d1d5db', 'Gris espacial' => '#52525b',
    'Medianoche' => '#1e293b', 'Blanco estelar' => '#f3eee4', 'Dorado' => '#e3c58f', 'Oro rosa' => '#e8b4a8',
    'Azul' => '#3b82f6', 'Azul cielo' => '#93c5fd', 'Azul Pacífico' => '#2f5d7c', 'Verde' => '#4d7c5b',
    'Verde alpino' => '#5f7a61', 'Morado' => '#a78bfa', 'Rosa' => '#f9a8d4', 'Amarillo' => '#fde047',
    'Rojo' => '#dc2626', 'Naranja' => '#f97316', 'Titanio natural' => '#b9b2a6', 'Titanio negro' => '#3f3f46',
    'Titanio blanco' => '#e7e5e4', 'Titanio azul' => '#4b5d74', 'Titanio desierto' => '#c8a982',
];
function color_hex(?string $color): string {
    foreach (COLORES as $n => $hex) if (mb_strtolower($n) === mb_strtolower(trim((string)$color))) return $hex;
    return '#94a3b8';
}
// Muestra de color + nombre, p. ej. ● Azul
function color_badge(?string $color): string {
    if (trim((string)$color) === '') return '<span class="text-muted">—</span>';
    return '<span class="color-chip"><span class="color-dot" style="background:' . color_hex($color) . '"></span>' . h($color) . '</span>';
}
// Nombre de producto con su color para listas y tickets: "iPhone 15 Pro · Negro"
function producto_label(string $nombre, ?string $color): string {
    return trim((string)$color) === '' ? $nombre : "{$nombre} · {$color}";
}

// Botón eliminar como formulario POST con token CSRF
function delete_button(int $id, string $confirm, string $field = 'eliminar'): string {
    return '<form method="POST" class="d-inline" data-confirm="' . h($confirm) . '">'
        . csrf_field()
        . '<input type="hidden" name="' . h($field) . '" value="' . $id . '">'
        . '<button type="submit" class="btn btn-xs btn-outline-danger" title="Eliminar"><i class="fa-solid fa-trash"></i></button>'
        . '</form>';
}

try {
    $pdo = app_pdo();
} catch(PDOException $e) {
    error_log('Conexión BD: ' . $e->getMessage());
    http_response_code(503);
    die("Error de conexión a la base de datos.");
}

// El usuario debe seguir existiendo; su rol se toma de la base (si lo cambian o eliminan, aplica de inmediato)
$st = $pdo->prepare("SELECT nombre, rol, debe_cambiar_clave FROM usuarios WHERE id=?");
$st->execute([$_SESSION['id_usuario'] ?? 0]);
$yo = $st->fetch();
if (!$yo) { session_unset(); session_destroy(); header("Location: index.php"); exit; }
$_SESSION['rol'] = $rol = $yo['rol'];
$_SESSION['nombre'] = $yo['nombre'];
// Contraseña inicial o restablecida: no se puede usar el sistema hasta cambiarla
if (!empty($yo['debe_cambiar_clave']) && basename($_SERVER['PHP_SELF']) !== 'cambiar_clave.php') {
    header("Location: cambiar_clave.php"); exit;
}
