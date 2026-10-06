<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';

$caja = caja_get($pdo, (int)($_GET['id'] ?? 0));
if (!$caja) die("Caja no encontrada.");
$r    = caja_resumen($pdo, $caja);
$conf = $pdo->query("SELECT * FROM configuracion WHERE id=1")->fetch() ?: [];
$abierta  = $caja['estado'] === 'Abierta';
$contado  = $abierta ? null : (float)$caja['efectivo_contado'];
$esperado = $abierta ? $r['efectivo_esperado'] : (float)$caja['efectivo_esperado'];
$dif      = $abierta ? null : round($contado - $esperado, 2);
$ef       = $r['medios']['Efectivo'];
$desc     = $abierta ? null : (caja_descuadres($pdo, (int)$caja['id'])[0] ?? null);
$ajustes  = $desc ? caja_ajustes($pdo, (int)$caja['id']) : [];
$otros    = array_values(array_filter(caja_descuadres_pendientes($pdo), fn($d) => (int)$d['id'] !== (int)$caja['id']));
$num      = str_pad($caja['id'], 4, '0', STR_PAD_LEFT);
$archivo  = "Cierre_caja_{$num}_" . date('Y-m-d', strtotime($caja['fecha_cierre'] ?? 'now')) . ".pdf";

function d(float $n): string { return '$' . number_format($n, 2); }
function fh(?string $f): string { return $f ? date('d/m/Y h:i A', strtotime($f)) : '—'; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cierre de caja #<?= $num ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #e2e8f0; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; color: #1e293b; font-size: 11px; }
        .toolbar { position: sticky; top: 0; z-index: 5; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #0f172a; }
        .toolbar button, .toolbar a { border: none; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; font-family: inherit; }
        .b-pdf { background: #dc2626; color: #fff; } .b-print { background: #3b82f6; color: #fff; } .b-back { background: #e2e8f0; color: #1e293b; }
        #reporte { width: 794px; max-width: 100%; margin: 16px auto; background: #fff; padding: 32px 36px; }
        h1 { font-size: 18px; margin: 0; } h2 { font-size: 12.5px; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 2px solid #0f172a; text-transform: uppercase; letter-spacing: .04em; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px solid #0f172a; padding-bottom: 10px; }
        .head .emp { font-size: 15px; font-weight: 800; } .muted { color: #64748b; }
        .estado { display: inline-block; padding: 3px 10px; border-radius: 20px; font-weight: 700; font-size: 11px; }
        .e-cerrada { background: #dcfce7; color: #15803d; } .e-abierta { background: #fef9c3; color: #a16207; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f5f9; text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .04em; color: #475569; padding: 5px 6px; }
        td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        tr { page-break-inside: avoid; }
        .r { text-align: right; white-space: nowrap; } .b { font-weight: 700; }
        .tot td { border-top: 2px solid #0f172a; font-weight: 800; background: #f8fafc; }
        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 12px; }
        .kpi { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
        .kpi .l { font-size: 9px; text-transform: uppercase; color: #64748b; font-weight: 700; letter-spacing: .04em; }
        .kpi .v { font-size: 15px; font-weight: 800; margin-top: 2px; }
        .dif-ok { color: #15803d; } .dif-mas { color: #2563eb; } .dif-menos { color: #dc2626; }
        .items { margin: 0; padding-left: 14px; } .items li { margin: 1px 0; }
        .gift { color: #be185d; font-weight: 600; }
        .firmas { display: flex; gap: 40px; margin-top: 50px; } .firmas div { flex: 1; border-top: 1px solid #0f172a; padding-top: 4px; text-align: center; }
        .two { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 6px 10px; margin-top: 10px; }
        @media print { body { background: #fff; } .toolbar { display: none; } #reporte { margin: 0; width: auto; padding: 0; } }
        @media (max-width: 700px) { .grid, .two { grid-template-columns: 1fr; } .kpis { grid-template-columns: 1fr 1fr; } #reporte { padding: 16px; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button class="b-pdf" onclick="descargarPDF()">⬇ Descargar PDF</button>
    <button class="b-print" onclick="window.print()">🖨 Imprimir</button>
    <a class="b-back" href="caja.php">← Volver a Caja</a>
</div>

<div id="reporte">
    <div class="head">
        <div>
            <div class="emp"><?= h($conf['nombre_empresa'] ?? APP_NAME) ?></div>
            <div class="muted"><?= $conf['nit'] ? 'NIT ' . h($conf['nit']) . ' · ' : '' ?><?= h($conf['telefono'] ?? '') ?> <?= h($conf['direccion'] ?? '') ?></div>
        </div>
        <div style="text-align:right">
            <h1><?= $abierta ? 'Reporte parcial de caja' : 'Reporte de cierre de caja' ?> #<?= $num ?></h1>
            <span class="estado <?= $abierta ? 'e-abierta' : 'e-cerrada' ?>"><?= $abierta ? 'CAJA ABIERTA (parcial)' : 'CAJA CERRADA' ?></span>
        </div>
    </div>
    <div class="grid">
        <div><b>Apertura:</b> <?= fh($caja['fecha_apertura']) ?> · <?= h($caja['usuario_apertura'] ?? '—') ?></div>
        <div><b>Cierre:</b> <?= $abierta ? '— (aún abierta)' : fh($caja['fecha_cierre']) . ' · ' . h($caja['usuario_cierre'] ?? '—') ?></div>
        <div><b>Base inicial en efectivo:</b> <?= d($r['base']) ?></div>
        <div><b>Generado:</b> <?= date('d/m/Y h:i A') ?> por <?= h($_SESSION['nombre']) ?></div>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="l">Total vendido</div><div class="v"><?= d($r['total_ventas']) ?></div><div class="muted"><?= $r['num_ventas'] ?> ventas · <?= $r['unidades'] ?> und.</div></div>
        <div class="kpi"><div class="l">Efectivo en cajón</div><div class="v"><?= d($abierta ? $esperado : $contado) ?></div><div class="muted"><?= $abierta ? 'esperado a la fecha' : 'contado al cierre' ?></div></div>
        <div class="kpi"><div class="l">En cuentas</div><div class="v"><?= d($r['cuentas']) ?></div><div class="muted">transferencias + tarjeta</div></div>
        <div class="kpi"><div class="l">A cartera</div><div class="v"><?= d($r['cartera']) ?></div><div class="muted">terceros + crédito interno</div></div>
    </div>

    <div class="two">
        <div>
            <h2>Cuadre de efectivo</h2>
            <table>
                <tr><td>Base inicial</td><td class="r"><?= d($r['base']) ?></td></tr>
                <tr><td>+ Ventas en efectivo</td><td class="r"><?= d($ef['ventas']) ?></td></tr>
                <tr><td>+ Abonos de cartera en efectivo</td><td class="r"><?= d($ef['abonos']) ?></td></tr>
                <tr><td>+ Otros ingresos en efectivo</td><td class="r"><?= d($ef['ingresos']) ?></td></tr>
                <tr><td>− Egresos / gastos en efectivo</td><td class="r">−<?= d($ef['egresos']) ?></td></tr>
                <tr class="tot"><td>= Efectivo esperado</td><td class="r"><?= d($esperado) ?></td></tr>
                <?php if (!$abierta): ?>
                <tr><td class="b">Efectivo contado</td><td class="r b"><?= d($contado) ?></td></tr>
                <tr><td class="b">Diferencia</td>
                    <td class="r b <?= abs($dif) < 0.01 ? 'dif-ok' : ($dif > 0 ? 'dif-mas' : 'dif-menos') ?>">
                        <?= abs($dif) < 0.01 ? 'Cuadrada' : ($dif > 0 ? 'Sobrante ' : 'Faltante ') . d(abs($dif)) ?></td></tr>
                <?php if ($desc): ?>
                <tr><td class="b">Estado del descuadre</td>
                    <td class="r b <?= $desc['estado'] === 'Pendiente' ? 'dif-menos' : 'dif-ok' ?>">
                        <?= $desc['estado'] === 'Pendiente' ? 'PENDIENTE ' . d($desc['pendiente']) : 'CUADRADO ' . date('d/m/Y', strtotime($desc['ultimo_ajuste'])) ?></td></tr>
                <?php endif; ?>
                <?php endif; ?>
            </table>
        </div>
        <div>
            <h2>Dinero recibido por medio</h2>
            <table>
                <tr><th>Medio</th><th class="r">Entradas</th><th class="r">Salidas</th><th class="r">Neto</th></tr>
                <?php $tin = $tout = 0; foreach ($r['medios'] as $m => $t): $in = $t['ventas'] + $t['abonos'] + $t['ingresos']; $tin += $in; $tout += $t['egresos']; ?>
                <tr><td><?= h($m) ?></td><td class="r"><?= d($in) ?></td><td class="r"><?= d($t['egresos']) ?></td><td class="r b"><?= d($t['neto']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="tot"><td>Total</td><td class="r"><?= d($tin) ?></td><td class="r"><?= d($tout) ?></td><td class="r"><?= d($tin - $tout) ?></td></tr>
            </table>
            <p class="muted" style="margin:6px 0 0">No incluye la base inicial. Transferencias y tarjeta deben verse reflejadas en la cuenta bancaria / datáfono.</p>
        </div>
    </div>

    <div class="two">
        <div>
            <h2>Ventas por forma de pago</h2>
            <table>
                <tr><th>Forma de pago</th><th class="r">Monto</th></tr>
                <?php if (empty($r['por_forma'])): ?><tr><td colspan="2" class="muted">Sin ventas</td></tr><?php endif; ?>
                <?php foreach ($r['por_forma'] as $m => $monto): ?>
                <tr><td><?= h(forma_pago_label($m)) ?><?= in_array($m, METODOS_CARTERA, true) ? ' <span class="muted">(a cartera)</span>' : '' ?></td><td class="r"><?= d($monto) ?></td></tr>
                <?php if ($m === 'Terceros') foreach ($r['terceros_entidad'] as $ent => $mt): /* desglose por entidad, justo debajo de Terceros */ ?>
                <tr><td class="muted" style="padding-left:18px">↳ <?= h($ent) ?></td><td class="r muted"><?= d($mt) ?></td></tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
                <tr><td>Subtotal</td><td class="r"><?= d($r['subtotal']) ?></td></tr>
                <tr><td>Descuentos</td><td class="r">−<?= d($r['descuentos']) ?></td></tr>
                <tr class="tot"><td>Total vendido</td><td class="r"><?= d($r['total_ventas']) ?></td></tr>
            </table>
        </div>
        <div>
            <h2>Productos vendidos</h2>
            <table>
                <tr><th>Producto</th><th class="r">Cant.</th><th class="r">Total</th></tr>
                <?php if (empty($r['productos'])): ?><tr><td colspan="3" class="muted">Sin productos</td></tr><?php endif; ?>
                <?php foreach ($r['productos'] as $p): ?>
                <tr><td><?= h($p['nombre']) ?><?= $p['obsequios'] ? ' <span class="gift">+' . $p['obsequios'] . ' obsequio(s)</span>' : '' ?></td><td class="r"><?= $p['vendidos'] ?></td><td class="r"><?= d($p['total']) ?></td></tr>
                <?php endforeach; ?>
                <tr class="tot"><td>Total</td><td class="r"><?= $r['unidades'] ?></td><td class="r"><?= d(array_sum(array_column($r['productos'], 'total'))) ?></td></tr>
            </table>
        </div>
    </div>

    <h2>Detalle de ventas (<?= $r['num_ventas'] ?>)</h2>
    <table>
        <tr><th>Ticket</th><th>Hora</th><th>Cliente</th><th>Productos</th><th>Pago</th><th class="r">Total</th></tr>
        <?php if (empty($r['ventas'])): ?><tr><td colspan="6" class="muted">No se registraron ventas en esta caja.</td></tr><?php endif; ?>
        <?php foreach ($r['ventas'] as $v): ?>
        <tr>
            <td class="b">#<?= str_pad($v['id'], 4, '0', STR_PAD_LEFT) ?></td>
            <td style="white-space:nowrap"><?= date('d/m h:i A', strtotime($v['fecha'])) ?><br><span class="muted"><?= h($v['vendedor'] ?? '—') ?></span></td>
            <td><?= h($v['cliente'] ?? '—') ?><br><span class="muted"><?= h($v['documento'] ?? '') ?></span></td>
            <td><ul class="items">
                <?php foreach ($v['items'] as $it): ?>
                <li><?= (int)$it['cantidad'] ?> × <?= h(producto_label($it['nombre'], $it['color'])) ?>
                    <?= $it['es_obsequio'] ? '<span class="gift">(obsequio)</span>' : '<span class="muted">' . d((float)$it['subtotal']) . '</span>' ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ((float)$v['descuento'] > 0): ?><span class="muted">Descuento: −<?= d((float)$v['descuento']) ?></span><?php endif; ?>
            <?php if (trim((string)$v['observacion']) !== ''): ?><br><span class="muted">Obs: <?= h($v['observacion']) ?></span><?php endif; ?>
            </td>
            <td><?php foreach ($v['pagos'] as $p): ?><?= h(forma_pago_label($p['metodo'])) ?><?= $p['entidad'] ? ' (' . h($p['entidad']) . ')' : '' ?><?= count($v['pagos']) > 1 ? ': ' . d((float)$p['monto']) : '' ?><br><?php endforeach; ?>
                <?php if ($v['autoriza']): ?><span class="muted">Autorizó: <?= h($v['autoriza']) ?></span><?php endif; ?></td>
            <td class="r b"><?= d((float)$v['total']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>Abonos de cartera recibidos (<?= count($r['abonos']) ?>)</h2>
    <table>
        <tr><th>Hora</th><th>Ticket</th><th>Cliente</th><th>Medio</th><th>Recibió</th><th>Observación</th><th class="r">Monto</th></tr>
        <?php if (empty($r['abonos'])): ?><tr><td colspan="7" class="muted">Sin abonos.</td></tr><?php endif; ?>
        <?php foreach ($r['abonos'] as $a): ?>
        <tr><td style="white-space:nowrap"><?= date('d/m h:i A', strtotime($a['fecha'])) ?></td><td>#<?= str_pad($a['id_venta'], 4, '0', STR_PAD_LEFT) ?></td>
            <td><?= h($a['cliente'] ?? '—') ?></td><td><?= h($a['metodo']) ?></td><td><?= h($a['usuario'] ?? '—') ?></td><td><?= h($a['observacion'] ?? '') ?></td><td class="r b"><?= d((float)$a['monto']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($r['abonos']): ?><tr class="tot"><td colspan="6">Total abonos</td><td class="r"><?= d($r['total_abonos']) ?></td></tr><?php endif; ?>
    </table>

    <h2>Ingresos y egresos manuales (<?= count($r['manuales']) ?>)</h2>
    <table>
        <tr><th>Hora</th><th>Tipo</th><th>Medio</th><th>Descripción</th><th>Usuario</th><th class="r">Monto</th></tr>
        <?php if (empty($r['manuales'])): ?><tr><td colspan="6" class="muted">Sin movimientos manuales.</td></tr><?php endif; ?>
        <?php foreach ($r['manuales'] as $m): ?>
        <tr><td style="white-space:nowrap"><?= date('d/m h:i A', strtotime($m['fecha'])) ?></td><td><?= h($m['tipo']) ?></td><td><?= h($m['metodo'] ?? 'Efectivo') ?></td>
            <td><?= h($m['descripcion'] ?? '') ?></td><td><?= h($m['usuario'] ?? '—') ?></td>
            <td class="r b" style="color:<?= $m['tipo'] === 'Egreso' ? '#dc2626' : '#15803d' ?>"><?= $m['tipo'] === 'Egreso' ? '−' : '+' ?><?= d((float)$m['monto']) ?></td></tr>
        <?php endforeach; ?>
    </table>

    <?php if ($desc): ?>
    <h2>Descuadre de esta caja</h2>
    <p style="margin:0 0 6px">
        <b><?= $desc['tipo'] ?> de <?= d($desc['monto']) ?></b> en el cierre del <?= fh($caja['fecha_cierre']) ?> (cerró <?= h($caja['usuario_cierre'] ?? '—') ?>).
        Ajustado: <?= d($desc['ajustado']) ?> ·
        <b class="<?= $desc['estado'] === 'Pendiente' ? 'dif-menos' : 'dif-ok' ?>"><?= $desc['estado'] === 'Pendiente' ? 'Pendiente: ' . d($desc['pendiente']) : 'Cuadrado' ?></b>
    </p>
    <table>
        <tr><th>Fecha del ajuste</th><th>Registrado en</th><th>Tipo</th><th>Medio</th><th>Detalle</th><th>Usuario</th><th class="r">Monto</th></tr>
        <?php if (empty($ajustes)): ?><tr><td colspan="7" class="muted">Aún no se ha registrado ninguna <?= $desc['tipo'] === 'Faltante' ? 'reposición' : 'salida' ?> para cuadrar este descuadre.</td></tr><?php endif; ?>
        <?php foreach ($ajustes as $a): ?>
        <tr><td style="white-space:nowrap"><?= fh($a['fecha']) ?></td><td>Caja #<?= (int)$a['id_caja'] ?></td><td><?= h($a['tipo']) ?></td><td><?= h($a['metodo'] ?? 'Efectivo') ?></td>
            <td><?= h($a['descripcion'] ?? '') ?></td><td><?= h($a['usuario'] ?? '—') ?></td><td class="r b"><?= d((float)$a['monto']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ($otros): ?>
    <h2>Descuadres pendientes de otras cajas (<?= count($otros) ?>)</h2>
    <table>
        <tr><th>Caja</th><th>Fecha del cierre</th><th>Cerró</th><th>Tipo</th><th class="r">Diferencia</th><th class="r">Ajustado</th><th class="r">Pendiente</th></tr>
        <?php foreach ($otros as $o): ?>
        <tr><td>#<?= $o['id'] ?></td><td><?= fh($o['fecha_cierre']) ?></td><td><?= h($o['usuario_cierre'] ?? '—') ?></td><td><?= $o['tipo'] ?></td>
            <td class="r"><?= d($o['monto']) ?></td><td class="r"><?= d($o['ajustado']) ?></td><td class="r b <?= $o['tipo'] === 'Faltante' ? 'dif-menos' : 'dif-mas' ?>"><?= d($o['pendiente']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="tot"><td colspan="6">Total pendiente</td><td class="r"><?= d(array_sum(array_column($otros, 'pendiente'))) ?></td></tr>
    </table>
    <?php endif; ?>

    <?php if ($caja['obs_apertura'] || $caja['obs_cierre']): ?>
    <div class="note">
        <?php if ($caja['obs_apertura']): ?><div><b>Obs. apertura:</b> <?= h($caja['obs_apertura']) ?></div><?php endif; ?>
        <?php if ($caja['obs_cierre']): ?><div><b>Obs. cierre:</b> <?= h($caja['obs_cierre']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="firmas">
        <div>Entrega: <?= h($caja['usuario_cierre'] ?? '') ?></div>
        <div>Recibe / Revisa</div>
    </div>
    <p class="muted" style="text-align:center;margin:28px 0 0;font-size:9px"><?= h(app_copyright()) ?></p>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function descargarPDF() {
    if (typeof html2pdf === 'undefined') { window.print(); return; }   // sin internet: imprimir → Guardar como PDF
    html2pdf().set({
        margin: [8, 8, 10, 8],
        filename: <?= json_encode($archivo) ?>,
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' },
        pagebreak: { mode: ['css', 'legacy'], avoid: 'tr' },
    }).from(document.getElementById('reporte')).save();
}
<?php if (isset($_GET['cerrada'])): ?>
window.addEventListener('load', () => setTimeout(descargarPDF, 400));
<?php endif; ?>
</script>
</body>
</html>
