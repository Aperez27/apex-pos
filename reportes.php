<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';
include 'includes/header.php';
if ($rol !== 'admin') { echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }
$inicio  = date('Y-m-01');
$fin     = date('Y-m-d');
$ver_todos  = ($_GET['descuadres'] ?? '') === 'todos';
$descuadres = caja_descuadres($pdo);
$pendientes = array_filter($descuadres, fn($d) => $d['estado'] === 'Pendiente');
$lista      = $ver_todos ? $descuadres : $pendientes;
$falt_pend  = array_sum(array_map(fn($d) => $d['tipo'] === 'Faltante' ? $d['pendiente'] : 0, $pendientes));
$sobr_pend  = array_sum(array_map(fn($d) => $d['tipo'] === 'Sobrante' ? $d['pendiente'] : 0, $pendientes));
?>

<div class="page-header">
    <div><h1>Reportes</h1><p>Exporta datos a formato CSV/Excel</p></div>
</div>

<div class="row g-3">
    <!-- Ventas -->
    <div class="col-md-4">
        <div class="card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-cash-register"></i></div>
                <div><h6 class="mb-0 fw-700" style="font-weight:700">Reporte de Ventas</h6><div style="font-size:.78rem;color:#64748b">Todas las transacciones</div></div>
            </div>
            <p class="text-muted small mb-3">Resumen con indicadores, ventas por forma de pago y por vendedor, detalle de cada venta y de los productos vendidos.</p>
            <form action="exportar.php" method="GET" class="mt-auto">
                <div class="mb-2"><label class="form-label">Desde</label><input type="date" name="inicio" class="form-control" value="<?= $inicio ?>" required></div>
                <div class="mb-3"><label class="form-label">Hasta</label><input type="date" name="fin" class="form-control" value="<?= $fin ?>" required></div>
                <button type="submit" class="btn btn-success w-100"><i class="fa-solid fa-file-excel me-2"></i>Descargar Excel</button>
            </form>
        </div>
    </div>

    <!-- Inventario -->
    <div class="col-md-4">
        <div class="card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa-solid fa-box-archive"></i></div>
                <div><h6 class="mb-0 fw-700" style="font-weight:700">Reporte de Inventario</h6><div style="font-size:.78rem;color:#64748b">Stock y valoración</div></div>
            </div>
            <p class="text-muted small mb-3">Productos por categoría con color, costo, precio, margen, stock y valor del inventario. Resalta agotados y stock bajo.</p>
            <div class="mt-auto">
                <a href="exportar_inventario.php" class="btn btn-primary w-100"><i class="fa-solid fa-file-excel me-2"></i>Descargar Excel</a>
            </div>
        </div>
    </div>

    <!-- Cartera -->
    <div class="col-md-4">
        <div class="card p-4 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="stat-icon" style="background:#fef9c3;color:#d97706"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div><h6 class="mb-0 fw-700" style="font-weight:700">Cartera Pendiente</h6><div style="font-size:.78rem;color:#64748b">Crédito interno y terceros</div></div>
            </div>
            <p class="text-muted small mb-3">Cuentas con saldo pendiente, días de antigüedad, quién autorizó, y todos los abonos recibidos con su método de pago.</p>
            <div class="mt-auto">
                <a href="exportar_creditos.php" class="btn btn-warning w-100"><i class="fa-solid fa-file-excel me-2"></i>Descargar Excel</a>
            </div>
        </div>
    </div>
</div>

<!-- Descuadres de caja -->
<div class="card mt-4" id="descuadres">
    <div class="card-header-custom d-flex flex-wrap align-items-center gap-2">
        <div class="stat-icon" style="background:#fee2e2;color:#dc2626;width:38px;height:38px"><i class="fa-solid fa-scale-unbalanced"></i></div>
        <div>
            <h6 class="mb-0" style="font-weight:700">Descuadres de caja</h6>
            <div style="font-size:.78rem;color:#64748b">Faltantes y sobrantes de cada cierre; quedan pendientes hasta que se cuadran en Caja</div>
        </div>
        <div class="ms-auto d-flex flex-wrap gap-2 align-items-center">
            <span style="font-size:.82rem">Faltante pendiente: <b class="text-danger">$<?= number_format($falt_pend, 2) ?></b></span>
            <?php if ($sobr_pend > 0): ?><span style="font-size:.82rem">Sobrante pendiente: <b class="text-primary">$<?= number_format($sobr_pend, 2) ?></b></span><?php endif; ?>
            <div class="btn-group btn-group-sm">
                <a href="reportes.php#descuadres" class="btn btn-sm <?= $ver_todos ? 'btn-outline-secondary' : 'btn-dark' ?>">Pendientes (<?= count($pendientes) ?>)</a>
                <a href="reportes.php?descuadres=todos#descuadres" class="btn btn-sm <?= $ver_todos ? 'btn-dark' : 'btn-outline-secondary' ?>">Todos (<?= count($descuadres) ?>)</a>
            </div>
            <a href="exportar_descuadres.php" class="btn btn-sm btn-success"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Caja</th><th>Fecha del cierre</th><th>Cerró</th><th>Tipo</th><th class="text-end">Esperado</th><th class="text-end">Contado</th><th class="text-end">Diferencia</th><th class="text-end">Ajustado</th><th class="text-end">Pendiente</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($lista)): ?>
                <tr><td colspan="11"><div class="empty-state"><i class="fa-solid fa-scale-balanced"></i><?= $ver_todos ? 'No hay descuadres registrados' : 'No hay descuadres pendientes' ?></div></td></tr>
            <?php else: foreach ($lista as $d): ?>
                <tr>
                    <td style="font-weight:600">#<?= $d['id'] ?></td>
                    <td style="font-size:.82rem"><?= date('d/m/Y h:i A', strtotime($d['fecha_cierre'])) ?></td>
                    <td style="font-size:.82rem"><?= h($d['usuario_cierre'] ?? '—') ?></td>
                    <td><span class="badge <?= $d['tipo'] === 'Faltante' ? 'badge-danger' : 'badge-info' ?>"><?= $d['tipo'] ?></span></td>
                    <td class="text-end">$<?= number_format((float)$d['efectivo_esperado'], 2) ?></td>
                    <td class="text-end">$<?= number_format((float)$d['efectivo_contado'], 2) ?></td>
                    <td class="text-end" style="font-weight:600">$<?= number_format($d['monto'], 2) ?></td>
                    <td class="text-end">$<?= number_format($d['ajustado'], 2) ?></td>
                    <td class="text-end" style="font-weight:700">$<?= number_format($d['pendiente'], 2) ?></td>
                    <td><?= $d['estado'] === 'Pendiente'
                        ? '<span class="badge badge-danger">Pendiente</span>'
                        : '<span class="badge badge-success">Cuadrado ' . date('d/m/Y', strtotime($d['ultimo_ajuste'])) . '</span>' ?></td>
                    <td><a href="caja_reporte.php?id=<?= $d['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="Reporte del cierre"><i class="fa-solid fa-file-pdf"></i></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pendientes): ?><div class="px-3 py-2" style="font-size:.78rem;color:#64748b">Para cuadrar un descuadre ve a <a href="caja.php">Caja</a> → «Descuadres pendientes» → Cuadrar.</div><?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
