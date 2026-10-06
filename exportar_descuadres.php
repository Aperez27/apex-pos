<?php
require 'config/conexion.php';
require 'includes/xlsx.php';
require 'includes/caja_funciones.php';
if ($rol !== 'admin') { header("Location: dashboard.php"); exit; }

$conf = $pdo->query("SELECT nombre_empresa FROM configuracion WHERE id=1")->fetch();
$empresa = $conf['nombre_empresa'] ?? 'Mi tienda';

$descuadres = caja_descuadres($pdo);
$filas = array_map(fn($d) => [
    '#' . $d['id'], $d['fecha_apertura'], $d['fecha_cierre'], $d['usuario_cierre'] ?? '—', $d['tipo'],
    $d['efectivo_esperado'], $d['efectivo_contado'], $d['monto'], $d['ajustado'], $d['pendiente'],
    $d['estado'], $d['estado'] === 'Cuadrado' ? $d['ultimo_ajuste'] : '', $d['obs_cierre'] ?? '',
], $descuadres);

// Ajustes registrados para cuadrar los descuadres
$ajustes = array_map(fn($a) => [
    '#' . $a['ajusta_caja'], $a['fecha'], '#' . $a['id_caja'], $a['tipo'], $a['metodo'] ?? 'Efectivo',
    $a['monto'], $a['usuario'] ?? '—', $a['descripcion'] ?? '',
], $pdo->query("SELECT m.*, u.nombre AS usuario FROM caja_chica m LEFT JOIN usuarios u ON m.id_usuario=u.id
    WHERE m.ajusta_caja IS NOT NULL ORDER BY m.fecha DESC")->fetchAll());

$pend = array_filter($descuadres, fn($d) => $d['estado'] === 'Pendiente');
$x = new Xlsx('Descuadres de caja');
$sh = $x->sheet('Descuadres');
$sh->widths = [10, 17, 17, 18, 12];
$x->header($sh, 'Descuadres de Caja', [
    $empresa . ' · Corte al ' . date('d/m/Y h:i a'),
    'Generado por ' . ($_SESSION['nombre'] ?? $_SESSION['usuario']),
], 13);
$x->kpis($sh, [
    ['Descuadres pendientes', count($pend), 'int'],
    ['Faltante pendiente', array_sum(array_map(fn($d) => $d['tipo'] === 'Faltante' ? $d['pendiente'] : 0, $pend)), 'money'],
    ['Sobrante pendiente', array_sum(array_map(fn($d) => $d['tipo'] === 'Sobrante' ? $d['pendiente'] : 0, $pend)), 'money'],
], 3);
$x->table($sh, [
    ['Caja', 'center', 10], ['Apertura', 'datetime', 17], ['Cierre', 'datetime', 17], ['Cerró', 'text', 18],
    ['Tipo', 'text', 12], ['Esperado', 'money', 16], ['Contado', 'money', 16], ['Diferencia', 'money', 16],
    ['Ajustado', 'money', 16], ['Pendiente', 'money', 16], ['Estado', 'text', 12], ['Cuadrado el', 'datetime', 17],
    ['Observación del cierre', 'text', 34],
], $filas, ['label' => 'TOTALES', 'sum' => [7, 8, 9]], [
    'red' => fn($f) => $f[10] === 'Pendiente' ? [9, 10] : [],
]);

$sh = $x->sheet('Ajustes');
$x->header($sh, 'Ajustes registrados para cuadrar', [$empresa], 8);
$x->table($sh, [
    ['Caja descuadrada', 'center', 12], ['Fecha', 'datetime', 17], ['Registrado en', 'center', 12], ['Tipo', 'text', 10],
    ['Medio', 'text', 14], ['Monto', 'money', 16], ['Usuario', 'text', 18], ['Detalle', 'text', 44],
], $ajustes, ['label' => 'TOTAL', 'sum' => [5]]);

$x->download('Descuadres_caja_' . date('Ymd') . '.xlsx');
