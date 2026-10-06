<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';

$ventas_hoy     = (float)($pdo->query("SELECT IFNULL(SUM(total),0) FROM ventas WHERE DATE(fecha)=CURDATE() AND estado NOT IN ('Devolucion')")->fetchColumn());
$fiados_pend    = (float)($pdo->query("SELECT IFNULL(SUM(saldo_pendiente),0) FROM ventas WHERE saldo_pendiente>0")->fetchColumn());
$caja           = caja_abierta($pdo);
$caja_res       = $caja ? caja_resumen($pdo, $caja) : null;
$desc_pend      = caja_descuadres_pendientes($pdo);
$falt_pend      = array_sum(array_map(fn($d) => $d['tipo'] === 'Faltante' ? $d['pendiente'] : 0, $desc_pend));
$total_prod     = (int)($pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn());
$total_ventas   = (int)($pdo->query("SELECT COUNT(*) FROM ventas WHERE DATE(fecha)=CURDATE()")->fetchColumn());

$recientes = $pdo->query("SELECT v.id, v.fecha, v.total, v.estado, c.nombre as cliente
    FROM ventas v LEFT JOIN clientes c ON v.id_cliente=c.id
    ORDER BY v.fecha DESC LIMIT 8")->fetchAll();

$stock_bajo = $pdo->query("SELECT nombre, color, stock FROM productos WHERE stock <= 5 ORDER BY stock ASC LIMIT 10")->fetchAll();
include 'includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p>Panel operativo — <?php $dias = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado']; $meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre']; ?><?= ucfirst($dias[date('w')]) . ', ' . date('j') . ' de ' . $meses[date('n') - 1] . ' de ' . date('Y') ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="ventas.php" class="btn btn-primary"><i class="fa-solid fa-cart-plus me-1"></i> Nueva Venta</a>
        <a href="caja.php" class="btn btn-outline-secondary"><i class="fa-solid fa-vault me-1"></i> Caja</a>
    </div>
</div>

<?php if ($desc_pend): ?>
<a href="caja.php" class="alert alert-danger d-flex align-items-center gap-2 mb-3 text-decoration-none" style="font-size:.86rem">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span><b><?= count($desc_pend) ?> descuadre<?= count($desc_pend) !== 1 ? 's' : '' ?> de caja pendiente<?= count($desc_pend) !== 1 ? 's' : '' ?></b>
    <?= $falt_pend > 0 ? '— faltante por reponer: $' . number_format($falt_pend, 0, '.', ',') : '' ?>. Ver en Caja →</span>
</a>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label mb-2">Ventas hoy</div>
                    <div class="stat-value text-success">$<?= number_format($ventas_hoy, 0, '.', ',') ?></div>
                    <div class="mt-1" style="font-size:.75rem;color:#64748b"><?= $total_ventas ?> transacción<?= $total_ventas !== 1 ? 'es' : '' ?></div>
                </div>
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-cash-register"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label mb-2">Cartera pendiente</div>
                    <div class="stat-value text-warning">$<?= number_format($fiados_pend, 0, '.', ',') ?></div>
                    <div class="mt-1" style="font-size:.75rem;color:#64748b">Por cobrar</div>
                </div>
                <div class="stat-icon" style="background:#fef9c3;color:#d97706"><i class="fa-solid fa-file-invoice-dollar"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <?php if ($caja): ?>
                    <div class="stat-label mb-2">Efectivo en caja #<?= $caja['id'] ?></div>
                    <div class="stat-value text-primary">$<?= number_format($caja_res['efectivo_esperado'], 0, '.', ',') ?></div>
                    <div class="mt-1" style="font-size:.75rem;color:#16a34a"><i class="fa-solid fa-lock-open me-1"></i>Abierta desde <?= date('d/m h:i A', strtotime($caja['fecha_apertura'])) ?></div>
                    <?php else: ?>
                    <div class="stat-label mb-2">Caja</div>
                    <div class="stat-value text-danger">Cerrada</div>
                    <div class="mt-1" style="font-size:.75rem"><a href="caja.php">Abrir caja →</a></div>
                    <?php endif; ?>
                </div>
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa-solid fa-vault"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label mb-2">Productos</div>
                    <div class="stat-value text-secondary"><?= $total_prod ?></div>
                    <div class="mt-1" style="font-size:.75rem;color:#64748b">En inventario</div>
                </div>
                <div class="stat-icon" style="background:#f1f5f9;color:#475569"><i class="fa-solid fa-box-archive"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent sales -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-700" style="font-weight:700">Ventas recientes</h6>
                <a href="ventas.php" class="btn btn-sm btn-outline-primary">Ver POS</a>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th>Total</th>
                            <th>Fecha</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($recientes)): ?>
                        <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-receipt"></i>No hay ventas aún</div></td></tr>
                        <?php else: foreach($recientes as $v):
                            $estadoClass = match($v['estado']) {
                                'Vendido','Efectivo','Tarjeta','Transferencia' => 'badge-success',
                                'Fiado','Terceros','Credito Interno','Credito RP' => 'badge-warning',
                                'Devolucion' => 'badge-danger',
                                default => 'badge-info',
                            };
                        ?>
                        <tr>
                            <td><span class="fw-600" style="font-weight:600">#<?= str_pad($v['id'],4,'0',STR_PAD_LEFT) ?></span></td>
                            <td><?= h($v['cliente'] ?? '—') ?></td>
                            <td><span class="badge <?= $estadoClass ?>"><?= h(forma_pago_label($v['estado'])) ?></span></td>
                            <td class="fw-600" style="font-weight:600">$<?= number_format($v['total'],2) ?></td>
                            <td style="color:#64748b;font-size:.8rem"><?= date('d/m H:i', strtotime($v['fecha'])) ?></td>
                            <td><a href="ticket.php?id=<?= $v['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary"><i class="fa-solid fa-receipt"></i></a></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Stock alerts & Quick actions -->
    <div class="col-lg-4 d-flex flex-column gap-3">
        <!-- Stock alerts -->
        <div class="card">
            <div class="card-header-custom d-flex align-items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-warning"></i>
                <h6 class="mb-0 fw-700" style="font-weight:700">Stock bajo <span class="badge badge-warning ms-1"><?= count($stock_bajo) ?></span></h6>
            </div>
            <div class="p-0">
                <?php if(empty($stock_bajo)): ?>
                <div class="empty-state py-3"><i class="fa-solid fa-check-circle" style="color:#22c55e;opacity:.5"></i>Inventario estable</div>
                <?php else: foreach($stock_bajo as $b): ?>
                <div class="d-flex justify-content-between align-items-center px-3 py-2" style="border-bottom:1px solid #f1f5f9">
                    <span style="font-size:.84rem"><?php if ($b['color']): ?><span class="color-dot me-1" style="background:<?= color_hex($b['color']) ?>"></span><?php endif; ?><?= h(producto_label($b['nombre'], $b['color'])) ?></span>
                    <span class="badge badge-danger"><?= $b['stock'] ?> und.</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Quick actions -->
        <div class="card p-3">
            <h6 class="fw-700 mb-3" style="font-weight:700">Acciones rápidas</h6>
            <div class="d-grid gap-2">
                <a href="ventas.php" class="btn btn-primary"><i class="fa-solid fa-cash-register me-2"></i>Registrar Venta POS</a>
                <a href="abonos.php" class="btn btn-outline-warning"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Registrar Abono</a>
                <a href="caja.php" class="btn btn-outline-secondary"><i class="fa-solid fa-vault me-2"></i><?= $caja ? 'Ver / cerrar caja' : 'Abrir caja' ?></a>
                <?php if($rol === 'admin'): ?>
                <a href="productos.php" class="btn btn-outline-secondary"><i class="fa-solid fa-box-archive me-2"></i>Gestionar Inventario</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
