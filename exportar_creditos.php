<?php
require 'config/conexion.php';
require 'includes/xlsx.php';
if ($rol !== 'admin') { header("Location: dashboard.php"); exit; }

$conf = $pdo->query("SELECT nombre_empresa FROM configuracion WHERE id=1")->fetch();
$empresa = $conf['nombre_empresa'] ?? 'Mi tienda';

$pendientes = $pdo->query("
    SELECT v.id, v.fecha, c.nombre as cliente, c.documento, c.telefono,
           v.estado, v.entidad_financiera, ua.nombre as autoriza,
           v.total, v.saldo_pendiente,
           (SELECT IFNULL(SUM(a.monto),0) FROM abonos a WHERE a.id_venta=v.id) as abonado,
           (SELECT MAX(a.fecha) FROM abonos a WHERE a.id_venta=v.id) as ultimo_abono,
           DATEDIFF(CURDATE(), DATE(v.fecha)) as dias
    FROM ventas v
    LEFT JOIN clientes c  ON v.id_cliente=c.id
    LEFT JOIN usuarios ua ON v.id_autoriza=ua.id
    WHERE v.saldo_pendiente > 0
    ORDER BY v.fecha ASC
")->fetchAll();

$filas = array_map(fn($p) => [
    '#' . str_pad($p['id'], 4, '0', STR_PAD_LEFT), $p['fecha'], (int)$p['dias'],
    $p['cliente'] ?? '—', (string)$p['documento'], (string)($p['telefono'] ?? ''),
    forma_pago_label($p['estado']), $p['entidad_financiera'] ?? '', $p['autoriza'] ?? '',
    $p['total'], $p['total'] - $p['saldo_pendiente'], $p['saldo_pendiente'], $p['ultimo_abono'] ?? '',
], $pendientes);

// Abonos recibidos de esas cuentas
$abonos = [];
if ($pendientes) {
    $ids = implode(',', array_map('intval', array_column($pendientes, 'id')));
    $abonos = array_map(fn($a) => [
        '#' . str_pad($a['id_venta'], 4, '0', STR_PAD_LEFT), $a['fecha'], $a['cliente'] ?? '—',
        $a['metodo'], $a['monto'], $a['usuario'] ?? '—', $a['observacion'] ?? '',
    ], $pdo->query("
        SELECT a.*, c.nombre as cliente, u.nombre as usuario
        FROM abonos a JOIN ventas v ON v.id=a.id_venta
        LEFT JOIN clientes c ON c.id=v.id_cliente LEFT JOIN usuarios u ON u.id=a.id_usuario
        WHERE a.id_venta IN ({$ids}) ORDER BY a.fecha DESC")->fetchAll());
}

$saldo = array_sum(array_column($filas, 11));
$x = new Xlsx('Reporte de Cartera');
$sh = $x->sheet('Cartera');
$sh->widths = [10, 17, 9, 30, 22];
$x->header($sh, 'Cartera Pendiente', [
    $empresa . ' · Corte al ' . date('d/m/Y h:i a'),
    'Generado por ' . ($_SESSION['nombre'] ?? $_SESSION['usuario']),
], 13);
$x->kpis($sh, [
    ['Cuentas por cobrar', count($filas), 'int'],
    ['Saldo total por cobrar', $saldo, 'money'],
    ['Cuentas con más de 30 días', count(array_filter($filas, fn($f) => $f[2] > 30)), 'int'],
], 3);
$x->table($sh, [
    ['Ticket', 'center', 10], ['Fecha venta', 'datetime', 17], ['Días', 'int', 9], ['Cliente', 'text', 28],
    ['Documento', 'text', 14], ['Teléfono', 'text', 14], ['Forma de pago', 'text', 16], ['Entidad', 'text', 14],
    ['Autorizó', 'text', 16], ['Total venta', 'money', 16], ['Pagado', 'money', 16], ['Saldo pendiente', 'money', 16],
    ['Último abono', 'datetime', 17],
], $filas, ['label' => 'TOTALES', 'sum' => [9, 10, 11]], [
    'red' => fn($f) => $f[2] > 30 ? [2, 11] : [11],
]);

$sh = $x->sheet('Abonos recibidos');
$x->header($sh, 'Abonos de las cuentas pendientes', [$empresa], 7);
$x->table($sh, [
    ['Ticket', 'center', 10], ['Fecha', 'datetime', 17], ['Cliente', 'text', 28], ['Método', 'text', 15],
    ['Monto', 'money', 16], ['Recibió', 'text', 18], ['Observación', 'text', 34],
], $abonos, ['label' => 'TOTAL', 'sum' => [4]]);

$x->download('Cartera_' . date('Ymd') . '.xlsx');
