<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';
// AJAX endpoint for abonos history
if (isset($_GET['ajax_abonos'])) {
    $vid = (int)$_GET['ajax_abonos'];
    $rows = $pdo->prepare("SELECT a.*, u.nombre as usuario FROM abonos a LEFT JOIN usuarios u ON a.id_usuario=u.id WHERE a.id_venta=? ORDER BY a.fecha DESC");
    $rows->execute([$vid]);
    $rows = $rows->fetchAll();
    if (empty($rows)) { echo '<p class="text-muted text-center py-3">No hay abonos registrados.</p>'; exit; }
    echo '<table class="table table-sm mb-0">';
    echo '<thead><tr><th>Fecha</th><th>Monto</th><th>Método</th><th>Observación</th><th>Recibió</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td style="font-size:.8rem">' . date('d/m/Y H:i', strtotime($r['fecha'])) . '</td>';
        echo '<td class="fw-600" style="font-weight:600;color:#16a34a">$' . number_format($r['monto'],2) . '</td>';
        echo '<td><span class="badge badge-info">' . h($r['metodo']) . '</span></td>';
        echo '<td style="font-size:.82rem">' . h($r['observacion'] ?? '—') . '</td>';
        echo '<td style="font-size:.8rem">' . h($r['usuario'] ?? '—') . '</td></tr>';
    }
    echo '</tbody></table>';
    exit;
}

$msg = ''; $msg_type = 'success';

if (isset($_POST['registrar_abono'])) {
    csrf_verify();
    $id_venta     = (int)$_POST['id_venta'];
    $monto_abono  = (float)$_POST['monto'];
    $obs          = trim($_POST['observacion'] ?? '');
    $metodo       = (string)($_POST['metodo'] ?? '');
    $caja         = caja_abierta($pdo);

    if (!$caja) {
        $msg = "La caja está cerrada. Ábrela (en Punto de Venta o Caja) antes de registrar abonos."; $msg_type = 'danger';
    } elseif ($monto_abono <= 0) {
        $msg = "El monto debe ser mayor a cero."; $msg_type = 'danger';
    } elseif (!in_array($metodo, METODOS_CAJA, true)) {
        $msg = "Indica cómo se recibió el abono: efectivo, transferencia o tarjeta."; $msg_type = 'danger';
    } else {
        $venta = $pdo->prepare("SELECT * FROM ventas WHERE id=?");
        $venta->execute([$id_venta]);
        $venta = $venta->fetch();

        if (!$venta) {
            $msg = "Venta no encontrada."; $msg_type = 'danger';
        } elseif ($monto_abono > (float)$venta['saldo_pendiente'] + 0.01) {
            $msg = "El abono ($" . number_format($monto_abono,2) . ") supera el saldo pendiente ($" . number_format($venta['saldo_pendiente'],2) . ")."; $msg_type = 'danger';
        } else {
            $pdo->beginTransaction();
            try {
                $caja = caja_abierta_bloqueada($pdo);
                if (!$caja) throw new RuntimeException("La caja se cerró. Ábrela e intenta de nuevo.");
                $st = $pdo->prepare("SELECT saldo_pendiente FROM ventas WHERE id=? FOR UPDATE");
                $st->execute([$id_venta]);
                $saldo = (float)$st->fetchColumn();
                if ($monto_abono > $saldo + 0.01) throw new RuntimeException("El abono supera el saldo pendiente actual ($" . number_format($saldo, 2) . ").");
                $nuevo_saldo = max(0, $saldo - $monto_abono);
                $pdo->prepare("INSERT INTO abonos (id_venta,monto,metodo,observacion,id_usuario,id_caja) VALUES (?,?,?,?,?,?)")
                    ->execute([$id_venta, $monto_abono, $metodo, $obs, $_SESSION['id_usuario'], $caja['id']]);
                $pdo->prepare("UPDATE ventas SET saldo_pendiente=? WHERE id=?")->execute([$nuevo_saldo, $id_venta]);

                $desc_caja = "Abono cartera — Ticket #{$id_venta} — {$metodo}" . ($obs !== '' ? " · {$obs}" : '');
                $pdo->prepare("INSERT INTO caja_chica (tipo,monto,metodo,descripcion,referencia_origen,id_usuario,id_caja) VALUES ('Ingreso',?,?,?,?,?,?)")
                    ->execute([$monto_abono, $metodo, $desc_caja, "Abono #{$id_venta}", $_SESSION['id_usuario'], $caja['id']]);

                $pdo->commit();
                $msg = "Abono de $" . number_format($monto_abono,2) . " ({$metodo}) registrado. Saldo restante: $" . number_format($nuevo_saldo,2);
                if ($nuevo_saldo <= 0) $msg .= " — Cuenta saldada.";
            } catch (Exception $e) {
                $pdo->rollBack();
                if (!$e instanceof RuntimeException) error_log('Abono: ' . $e->getMessage());
                $msg = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo registrar el abono. Intenta de nuevo."; $msg_type = 'danger';
            }
        }
    }
    flash_redirect('abonos.php', $msg, $msg_type);
}
[$msg, $msg_type] = flash_get();

// Todas las ventas con saldo pendiente (Crédito Interno, Terceros y su parte en pagos mixtos)
$pendientes = $pdo->query("
    SELECT v.id, v.fecha, v.total, v.saldo_pendiente, v.estado, v.entidad_financiera, ua.nombre as autoriza,
           c.nombre as cliente, c.documento, c.telefono,
           (SELECT COUNT(*) FROM abonos a WHERE a.id_venta=v.id) as num_abonos
    FROM ventas v
    LEFT JOIN clientes c ON v.id_cliente=c.id
    LEFT JOIN usuarios ua ON v.id_autoriza=ua.id
    WHERE v.saldo_pendiente > 0
    ORDER BY v.saldo_pendiente DESC, v.fecha DESC
")->fetchAll();

$total_cartera = array_sum(array_column($pendientes, 'saldo_pendiente'));
$caja_actual   = caja_abierta($pdo);
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Cartera</h1><p>Cuentas pendientes de cobro — <?= count($pendientes) ?> cuenta<?= count($pendientes) !== 1 ? 's' : '' ?></p></div>
    <div class="stat-card d-flex align-items-center gap-3 py-2 px-4">
        <div class="stat-icon" style="background:#fef9c3;color:#d97706"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        <div>
            <div class="stat-label">Total pendiente</div>
            <div class="stat-value text-warning" style="font-size:1.3rem">$<?= number_format($total_cartera,2) ?></div>
        </div>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-3">
    <!-- Abono form -->
    <div class="col-lg-4">
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700"><i class="fa-solid fa-hand-holding-dollar me-2 text-warning"></i>Registrar Abono</h6>
            <?php if(!$caja_actual): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.8rem"><i class="fa-solid fa-lock me-1"></i>La caja está cerrada: ábrela en <a href="caja.php">Caja</a> o en Punto de Venta para registrar abonos.</div>
            <?php endif; ?>
            <?php if(empty($pendientes)): ?>
            <div class="empty-state"><i class="fa-solid fa-check-circle" style="color:#22c55e;opacity:.4"></i>No hay cuentas pendientes</div>
            <?php else: ?>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Cuenta / Ticket</label>
                    <select name="id_venta" id="selVenta" class="form-select" required onchange="onVentaChange(this)">
                        <option value="">Seleccione cuenta...</option>
                        <?php foreach($pendientes as $f): ?>
                        <option value="<?= $f['id'] ?>" data-saldo="<?= $f['saldo_pendiente'] ?>">
                            #<?= str_pad($f['id'],4,'0',STR_PAD_LEFT) ?> — <?= h($f['cliente']) ?> — Saldo: $<?= number_format($f['saldo_pendiente'],2) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label">Monto del abono ($)</label>
                    <input type="number" name="monto" id="montoAbono" step="0.01" min="0.01" class="form-control" placeholder="0.00" required>
                    <div id="saldoInfo" class="mt-1" style="font-size:.78rem;color:#64748b"></div>
                </div>
                <div class="mb-1 mt-3">
                    <label class="form-label">¿Cómo pagó? *</label>
                    <div class="d-flex gap-2 metodo-abono">
                        <?php foreach (['Efectivo' => 'fa-money-bill-wave', 'Transferencia' => 'fa-building-columns', 'Tarjeta' => 'fa-credit-card'] as $m => $icono): ?>
                        <input type="radio" class="btn-check" name="metodo" id="met<?= $m ?>" value="<?= $m ?>" autocomplete="off" required>
                        <label class="btn btn-outline-secondary flex-fill py-2" for="met<?= $m ?>" style="font-size:.8rem">
                            <i class="fa-solid <?= $icono ?> d-block mb-1" style="font-size:1rem"></i><?= $m ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3 mt-3">
                    <label class="form-label">Observación</label>
                    <input type="text" name="observacion" class="form-control" placeholder="Ej. Nequi ref. 12345, pagó Addi…">
                </div>
                <button type="submit" name="registrar_abono" class="btn btn-warning w-100 fw-600" style="font-weight:600" <?= $caja_actual ? '' : 'disabled' ?>>
                    <i class="fa-solid fa-money-bill-wave me-2"></i>Registrar Abono
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending list -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header-custom">
                <h6 class="mb-0 fw-700" style="font-weight:700">Cuentas por cobrar</h6>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr><th>Ticket</th><th>Cliente</th><th>Teléfono</th><th>Tipo</th><th>Total</th><th>Saldo</th><th>Abonado</th><th>Fecha</th><th>Abonos</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pendientes)): ?>
                        <tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-check-double"></i>No hay cuentas pendientes</div></td></tr>
                        <?php else: foreach($pendientes as $f):
                            $abonado = (float)$f['total'] - (float)$f['saldo_pendiente'];
                            $pct = $f['total'] > 0 ? round($abonado / $f['total'] * 100) : 0;
                        ?>
                        <tr>
                            <td><span class="fw-600" style="font-weight:600">#<?= str_pad($f['id'],4,'0',STR_PAD_LEFT) ?></span></td>
                            <td>
                                <div style="font-weight:600;font-size:.875rem"><?= h($f['cliente']) ?></div>
                                <div style="font-size:.75rem;color:#64748b"><?= h($f['documento']) ?></div>
                            </td>
                            <td style="font-size:.82rem"><?= h($f['telefono'] ?? '—') ?></td>
                            <td><span class="badge badge-warning"><?= h(forma_pago_label($f['estado'])) ?></span>
                                <?php if ($f['entidad_financiera']): ?><div style="font-size:.72rem;color:#1d4ed8"><?= h($f['entidad_financiera']) ?></div><?php endif; ?>
                                <?php if ($f['autoriza']): ?><div style="font-size:.72rem;color:#7e22ce">Autorizó: <?= h($f['autoriza']) ?></div><?php endif; ?></td>
                            <td>$<?= number_format($f['total'],2) ?></td>
                            <td><span class="badge badge-danger" style="font-size:.82rem">$<?= number_format($f['saldo_pendiente'],2) ?></span></td>
                            <td>
                                <div style="font-size:.78rem;color:#64748b">$<?= number_format($abonado,2) ?></div>
                                <div style="height:4px;background:#e2e8f0;border-radius:4px;width:70px;margin-top:3px">
                                    <div style="height:4px;background:#f59e0b;border-radius:4px;width:<?= $pct ?>%"></div>
                                </div>
                            </td>
                            <td style="font-size:.78rem;color:#64748b"><?= date('d/m/Y', strtotime($f['fecha'])) ?></td>
                            <td>
                                <button class="btn btn-xs btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalHistorial" data-id="<?= $f['id'] ?>" data-cliente="<?= h($f['cliente']) ?>">
                                    <i class="fa-solid fa-clock-rotate-left"></i> <?= $f['num_abonos'] ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal historial abonos -->
<div class="modal fade" id="modalHistorial" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-700" style="font-weight:700">Historial de Abonos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="historialContent">
                <div class="text-center py-3 text-muted">Cargando...</div>
            </div>
        </div>
    </div>
</div>

<script>
function onVentaChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const saldo = parseFloat(opt.dataset.saldo || 0);
    const info  = document.getElementById('saldoInfo');
    const input = document.getElementById('montoAbono');
    if (saldo > 0) {
        info.textContent = 'Saldo pendiente: $' + saldo.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        input.max = saldo;
    } else {
        info.textContent = '';
    }
}

document.getElementById('modalHistorial').addEventListener('show.bs.modal', function(e) {
    const b  = e.relatedTarget;
    const id = b.dataset.id;
    const cl = b.dataset.cliente;
    this.querySelector('.modal-title').textContent = 'Abonos — Ticket #' + String(id).padStart(4,'0') + ' · ' + cl;
    // Load via fetch
    fetch('?ajax_abonos=' + id)
        .then(r => r.text())
        .then(html => document.getElementById('historialContent').innerHTML = html)
        .catch(() => document.getElementById('historialContent').innerHTML = '<p class="text-danger">Error al cargar.</p>');
});
</script>

<?php include 'includes/footer.php'; ?>
