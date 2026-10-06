<?php
require 'config/conexion.php';
require 'includes/xlsx.php';
if ($rol !== 'admin') { header("Location: dashboard.php"); exit; }

$conf = $pdo->query("SELECT nombre_empresa FROM configuracion WHERE id=1")->fetch();
$empresa = $conf['nombre_empresa'] ?? 'Mi tienda';

$productos = $pdo->query("
    SELECT p.nombre, p.color, cat.nombre as categoria, prov.nombre as proveedor,
           p.precio_compra, p.precio_venta, p.stock
    FROM productos p
    LEFT JOIN categorias cat ON p.id_categoria=cat.id
    LEFT JOIN proveedores prov ON p.id_proveedor=prov.id
    ORDER BY cat.nombre IS NULL, cat.nombre, p.nombre, p.color
")->fetchAll();

$filas = array_map(fn($p) => [
    $p['nombre'], $p['color'] ?? '', $p['categoria'] ?? 'Sin categoría', $p['proveedor'] ?? '',
    $p['precio_compra'], $p['precio_venta'],
    $p['precio_venta'] > 0 ? ($p['precio_venta'] - $p['precio_compra']) / $p['precio_venta'] : 0,
    (int)$p['stock'], $p['precio_compra'] * $p['stock'], $p['precio_venta'] * $p['stock'],
    $p['stock'] <= 0 ? 'Agotado' : ($p['stock'] <= 5 ? 'Stock bajo' : 'Disponible'),
], $productos);

$unidades  = array_sum(array_column($filas, 7));
$valCosto  = array_sum(array_column($filas, 8));
$valPvp    = array_sum(array_column($filas, 9));
$agotados  = count(array_filter($filas, fn($f) => $f[7] <= 0));
$bajos     = count(array_filter($filas, fn($f) => $f[7] > 0 && $f[7] <= 5));

$x = new Xlsx('Reporte de Inventario');
$sh = $x->sheet('Inventario');
$sh->widths = [36, 22];
$x->header($sh, 'Reporte de Inventario', [
    $empresa . ' · Corte al ' . date('d/m/Y h:i a'),
    'Generado por ' . ($_SESSION['nombre'] ?? $_SESSION['usuario']),
], 11);
$x->kpis($sh, [
    ['Productos registrados', count($filas), 'int'],
    ['Unidades en stock', $unidades, 'int'],
    ['Valor del inventario (a costo)', $valCosto, 'money'],
    ['Valor del inventario (a precio de venta)', $valPvp, 'money'],
    ['Ganancia potencial', $valPvp - $valCosto, 'money'],
    ['Agotados / con stock bajo (≤5)', "{$agotados} / {$bajos}", 'text'],
]);
$x->table($sh, [
    ['Producto', 'text', 34], ['Color', 'text', 16], ['Categoría', 'text', 15], ['Proveedor', 'text', 20],
    ['Costo', 'money', 15], ['Precio venta', 'money', 15], ['Margen', 'pct', 10], ['Stock', 'int', 9],
    ['Valor a costo', 'money', 17], ['Valor a precio venta', 'money', 18], ['Estado', 'center', 12],
], $filas, ['label' => 'TOTALES', 'sum' => [7, 8, 9]], [
    'red' => fn($f) => $f[7] <= 5 ? [7, 10] : [],
]);

$x->download('Inventario_' . date('Ymd') . '.xlsx');
