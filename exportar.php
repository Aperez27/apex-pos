<?php
require 'config/conexion.php';
require 'includes/xlsx.php';
if ($rol !== 'admin') { header("Location: dashboard.php"); exit; }

$valida = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : null;
$inicio = $valida($_GET['inicio'] ?? '') ?? date('Y-m-01');
$fin    = $valida($_GET['fin'] ?? '')    ?? date('Y-m-d');
if ($inicio > $fin) [$inicio, $fin] = [$fin, $inicio];

$conf = $pdo->query("SELECT nombre_empresa FROM configuracion WHERE id=1")->fetch();
$empresa = $conf['nombre_empresa'] ?? 'Mi tienda';

$stmt = $pdo->prepare("
    SELECT v.id, v.fecha, c.nombre as cliente, c.documento, u.nombre as vendedor,
           v.estado, v.entidad_financiera, v.referencia_credito, ua.nombre as autoriza,
           v.subtotal, v.descuento, v.total, v.saldo_pendiente, v.observacion
    FROM ventas v
    LEFT JOIN clientes c  ON v.id_cliente=c.id
    LEFT JOIN usuarios u  ON v.id_usuario=u.id
    LEFT JOIN usuarios ua ON v.id_autoriza=ua.id
    WHERE DATE(v.fecha) BETWEEN ? AND ?
    ORDER BY v.fecha DESC");
$stmt->execute([$inicio, $fin]);
$ventas = $stmt->fetchAll();

// Desglose de pagos de cada venta
$pagos = [];
$st = $pdo->prepare("SELECT pv.id_venta, pv.metodo, pv.monto FROM pagos_venta pv JOIN ventas v ON v.id=pv.id_venta WHERE DATE(v.fecha) BETWEEN ? AND ? ORDER BY pv.id");
$st->execute([$inicio, $fin]);
foreach ($st->fetchAll() as $p) $pagos[$p['id_venta']][] = $p;

$filas = [];
foreach ($ventas as $v) {
    $desglose = implode(' + ', array_map(fn($p) => forma_pago_label($p['metodo']) . ' $' . number_format($p['monto'], 0), $pagos[$v['id']] ?? []));
    $filas[] = [
        '#' . str_pad($v['id'], 4, '0', STR_PAD_LEFT), $v['fecha'], $v['cliente'] ?? '—', (string)$v['documento'], $v['vendedor'] ?? '—',
        forma_pago_label($v['estado']), $v['estado'] === 'Mixto' ? $desglose : '', $v['entidad_financiera'] ?? '', $v['autoriza'] ?? '',
        $v['subtotal'], $v['descuento'], $v['total'], $v['saldo_pendiente'], $v['observacion'] ?? '',
    ];
}

// Productos vendidos
$st = $pdo->prepare("
    SELECT d.id_venta, v.fecha, IFNULL(p.nombre,'(producto eliminado)') as producto, p.color, cat.nombre as categoria,
           d.cantidad, d.precio_unitario, d.subtotal, d.es_obsequio
    FROM detalles_venta d
    JOIN ventas v ON v.id=d.id_venta
    LEFT JOIN productos p ON p.id=d.id_producto
    LEFT JOIN categorias cat ON cat.id=p.id_categoria
    WHERE DATE(v.fecha) BETWEEN ? AND ?
    ORDER BY v.fecha DESC, d.id");
$st->execute([$inicio, $fin]);
$detalle = array_map(fn($d) => [
    '#' . str_pad($d['id_venta'], 4, '0', STR_PAD_LEFT), $d['fecha'], $d['producto'], $d['color'] ?? '', $d['categoria'] ?? '',
    $d['cantidad'], $d['precio_unitario'], $d['subtotal'], $d['es_obsequio'] ? 'Sí' : 'No',
], $st->fetchAll());

// Resumen por forma de pago (según el desglose) y por vendedor
$porMetodo = [];
foreach ($pagos as $idv => $lista) foreach ($lista as $p) {
    $k = forma_pago_label($p['metodo']);
    $porMetodo[$k] ??= [$k, [], 0];
    $porMetodo[$k][1][$idv] = 1;
    $porMetodo[$k][2] += $p['monto'];
}
$porMetodo = array_map(fn($m) => [$m[0], count($m[1]), $m[2]], array_values($porMetodo));
usort($porMetodo, fn($a, $b) => $b[2] <=> $a[2]);
$porVendedor = [];
foreach ($ventas as $v) {
    $k = $v['vendedor'] ?? '—';
    $porVendedor[$k] ??= [$k, 0, 0];
    $porVendedor[$k][1]++; $porVendedor[$k][2] += $v['total'];
}
$porVendedor = array_values($porVendedor);
usort($porVendedor, fn($a, $b) => $b[2] <=> $a[2]);

$nVentas = count($ventas);
$totalVendido = array_sum(array_column($ventas, 'total'));
$obsequios = array_sum(array_map(fn($d) => $d[8] === 'Sí' ? $d[5] : 0, $detalle));
$periodo = 'Periodo: ' . date('d/m/Y', strtotime($inicio)) . ' al ' . date('d/m/Y', strtotime($fin));
$generado = 'Generado el ' . date('d/m/Y \a \l\a\s h:i a') . ' por ' . ($_SESSION['nombre'] ?? $_SESSION['usuario']);

$x = new Xlsx('Reporte de Ventas');

// ── Hoja 1: Resumen
$sh = $x->sheet('Resumen');
$sh->landscape = false;
$sh->widths = [34, 24, 22];
$x->header($sh, 'Reporte de Ventas', [$empresa, $periodo, $generado], 3);
$x->section($sh, 'Indicadores del periodo');
$x->kpis($sh, [
    ['Número de ventas', $nVentas, 'int'],
    ['Total vendido', $totalVendido, 'money'],
    ['Ticket promedio', $nVentas ? $totalVendido / $nVentas : 0, 'money'],
    ['Descuentos otorgados', array_sum(array_column($ventas, 'descuento')), 'money'],
    ['Saldo pendiente (Cartera)', array_sum(array_column($ventas, 'saldo_pendiente')), 'money'],
    ['Obsequios entregados (und.)', $obsequios, 'int'],
]);
$x->section($sh, 'Ventas por forma de pago');
$x->table($sh, [['Forma de pago', 'text', 30], ['Ventas que la usan', 'int', 14], ['Total recibido', 'money', 20]], $porMetodo,
    ['label' => 'TOTAL', 'sum' => [2]], ['filter' => false, 'freeze' => false]);
$x->section($sh, 'Ventas por vendedor');
$x->table($sh, [['Vendedor', 'text', 30], ['N° ventas', 'int', 14], ['Total', 'money', 20]], $porVendedor,
    ['label' => 'TOTAL', 'sum' => [1, 2]], ['filter' => false, 'freeze' => false]);

// ── Hoja 2: Ventas
$sh = $x->sheet('Ventas');
$x->header($sh, 'Detalle de Ventas', [$empresa . ' · ' . $periodo, $generado], 14);
$x->table($sh, [
    ['Ticket', 'center', 9], ['Fecha', 'datetime', 17], ['Cliente', 'text', 28], ['Documento', 'text', 14], ['Vendedor', 'text', 16],
    ['Forma de pago', 'text', 16], ['Desglose (pago mixto)', 'text', 34], ['Entidad', 'text', 14], ['Autorizó', 'text', 16],
    ['Subtotal', 'money', 15], ['Descuento', 'money', 13], ['Total', 'money', 15], ['Saldo pendiente', 'money', 15], ['Observación', 'text', 28],
], $filas, ['label' => "TOTALES ({$nVentas} ventas)", 'sum' => [9, 10, 11, 12]], [
    'red' => fn($f) => (float)$f[12] > 0 ? [12] : [],
]);

// ── Hoja 3: Productos vendidos
$sh = $x->sheet('Productos vendidos');
$x->header($sh, 'Productos Vendidos', [$empresa . ' · ' . $periodo, $generado], 9);
$x->table($sh, [
    ['Ticket', 'center', 9], ['Fecha', 'datetime', 17], ['Producto', 'text', 34], ['Color', 'text', 16], ['Categoría', 'text', 14],
    ['Cantidad', 'int', 10], ['Precio unitario', 'money', 16], ['Subtotal', 'money', 16], ['Obsequio', 'center', 10],
], $detalle, ['label' => 'TOTALES', 'sum' => [5, 7]]);

$x->download('Ventas_' . date('Ymd', strtotime($inicio)) . '_' . date('Ymd', strtotime($fin)) . '.xlsx');
