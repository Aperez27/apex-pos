<?php
require 'config/conexion.php';
$id_venta = (int)($_GET['id'] ?? 0);
if (!$id_venta) die("Ticket no especificado.");

$conf  = $pdo->query("SELECT * FROM configuracion WHERE id=1")->fetch();
$stmt  = $pdo->prepare("SELECT v.*, u.nombre as vendedor, ua.nombre as autoriza, c.nombre as cliente, c.documento FROM ventas v LEFT JOIN usuarios u ON v.id_usuario=u.id LEFT JOIN usuarios ua ON v.id_autoriza=ua.id LEFT JOIN clientes c ON v.id_cliente=c.id WHERE v.id=?");
$stmt->execute([$id_venta]);
$venta = $stmt->fetch();
if (!$venta) die("Venta no encontrada.");

$det = $pdo->prepare("SELECT d.*, IFNULL(p.nombre, '(producto eliminado)') AS nombre, p.color FROM detalles_venta d LEFT JOIN productos p ON d.id_producto=p.id WHERE d.id_venta=?");
$det->execute([$id_venta]);
$detalles = $det->fetchAll();
$pg = $pdo->prepare("SELECT * FROM pagos_venta WHERE id_venta=? ORDER BY id");
$pg->execute([$id_venta]);
$pagos = $pg->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?= str_pad($id_venta,4,'0',STR_PAD_LEFT) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; font-size: 12px; background: #fff; color: #000; width: 320px; margin: 20px auto; padding: 15px; }
        .center { text-align: center; }
        .bold  { font-weight: bold; }
        .big   { font-size: 16px; }
        .divider { border: none; border-top: 1px dashed #555; margin: 8px 0; }
        .divider-solid { border: none; border-top: 2px solid #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        td.right { text-align: right; }
        .total-row td { font-weight: bold; font-size: 14px; padding: 3px 0; }
        .footer-msg { font-size: 10px; text-align: center; margin-top: 8px; line-height: 1.5; }
        .btn-area { text-align: center; margin-top: 15px; display: flex; gap: 8px; justify-content: center; }
        .btn { padding: 8px 20px; font-size: 12px; cursor: pointer; border: none; border-radius: 6px; font-family: sans-serif; }
        .btn-print { background: #0f172a; color: white; }
        .btn-back  { background: #e2e8f0; color: #1e293b; text-decoration: none; display: inline-flex; align-items: center; }
        @media print {
            body { margin: 0; padding: 0; width: 80mm; }
            .btn-area { display: none; }
        }
    </style>
</head>
<body>
<div class="center">
    <div class="bold big"><?= htmlspecialchars($conf['nombre_empresa'] ?? APP_NAME, ENT_QUOTES, 'UTF-8') ?></div>
    <div>NIT: <?= htmlspecialchars($conf['nit'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
    <div>Tel: <?= htmlspecialchars($conf['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
    <div><?= htmlspecialchars($conf['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
</div>
<hr class="divider-solid" style="margin:10px 0">

<table>
    <tr><td class="bold">Ticket:</td><td class="right">#<?= str_pad($id_venta,4,'0',STR_PAD_LEFT) ?></td></tr>
    <tr><td class="bold">Fecha:</td><td class="right"><?= date('d/m/Y H:i', strtotime($venta['fecha'])) ?></td></tr>
    <tr><td class="bold">Cajero:</td><td class="right"><?= htmlspecialchars($venta['vendedor'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td class="bold">Cliente:</td><td class="right"><?= htmlspecialchars($venta['cliente'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td class="bold">Doc:</td><td class="right"><?= htmlspecialchars($venta['documento'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
    <tr><td class="bold">Pago:</td><td class="right bold"><?= htmlspecialchars(forma_pago_label($venta['estado']), ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php if ($venta['estado'] === 'Mixto'): foreach ($pagos as $p): ?>
    <tr><td>&nbsp;· <?= htmlspecialchars(forma_pago_label($p['metodo']), ENT_QUOTES, 'UTF-8') ?></td><td class="right">$<?= number_format($p['monto'],2) ?></td></tr>
    <?php endforeach; endif; ?>
    <?php if($venta['entidad_financiera']): ?>
    <tr><td class="bold">Entidad:</td><td class="right"><?= htmlspecialchars($venta['entidad_financiera'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php endif; ?>
    <?php if($venta['referencia_credito']): ?>
    <tr><td class="bold">Ref.:</td><td class="right"><?= htmlspecialchars($venta['referencia_credito'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php endif; ?>
    <?php if($venta['autoriza']): ?>
    <tr><td class="bold">Autorizó:</td><td class="right"><?= htmlspecialchars($venta['autoriza'], ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php endif; ?>
</table>

<hr class="divider">

<table>
    <thead>
        <tr>
            <td class="bold" style="width:10%">Cant</td>
            <td class="bold" style="width:55%">Descripción</td>
            <td class="bold right" style="width:35%">Subtotal</td>
        </tr>
    </thead>
    <tbody>
        <?php foreach($detalles as $d): ?>
        <tr>
            <td><?= $d['cantidad'] ?></td>
            <td><?= htmlspecialchars(mb_strimwidth($d['nombre'], 0, 30, '…'), ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($d['color'])): ?><br>Color: <?= htmlspecialchars($d['color'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                <?php if (!empty($d['es_obsequio'])): ?><br><span class="bold">** OBSEQUIO **</span><?php endif; ?></td>
            <td class="right"><?= !empty($d['es_obsequio']) ? 'GRATIS' : '$' . number_format($d['subtotal'],2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<hr class="divider">

<table>
    <?php if((float)$venta['descuento'] > 0): ?>
    <tr><td>Subtotal:</td><td class="right">$<?= number_format($venta['subtotal'] ?? $venta['total'],2) ?></td></tr>
    <tr><td>Descuento:</td><td class="right">-$<?= number_format($venta['descuento'],2) ?></td></tr>
    <?php endif; ?>
    <tr class="total-row"><td>TOTAL:</td><td class="right">$<?= number_format($venta['total'],2) ?></td></tr>
    <?php if((float)$venta['saldo_pendiente'] > 0): ?>
    <tr><td>Saldo pend:</td><td class="right bold" style="color:#dc2626">$<?= number_format($venta['saldo_pendiente'],2) ?></td></tr>
    <?php endif; ?>
</table>

<hr class="divider">
<div class="footer-msg"><?= htmlspecialchars($conf['mensaje_ticket'] ?? '¡Gracias por su compra!', ENT_QUOTES, 'UTF-8') ?></div>

<div class="footer-msg" style="margin-top:4px;font-size:9px;color:#555">Sistema <?= APP_NAME ?> by <?= htmlspecialchars(APP_AUTHOR, ENT_QUOTES, 'UTF-8') ?></div>

<div class="btn-area">
    <button class="btn btn-print" onclick="window.print()">🖨️ Imprimir</button>
    <a href="ventas.php" class="btn btn-back">← Volver al POS</a>
</div>
</body>
</html>
