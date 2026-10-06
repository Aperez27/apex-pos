<?php
require 'config/conexion.php';
if ($rol !== 'admin') { include 'includes/header.php'; echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }

$msg = ''; $msg_type = 'success';

if (isset($_POST['registrar'])) {
    csrf_verify();
    $id_prod  = (int)$_POST['id_producto'];
    $tipo     = in_array($_POST['tipo'] ?? '', ['Averiado', 'Obsequio'], true) ? $_POST['tipo'] : 'Averiado';
    $cantidad = max(1, (int)$_POST['cantidad']);
    $motivo   = trim($_POST['motivo']);

    if ($id_prod > 0 && $motivo !== '') {
        $row = $pdo->prepare("SELECT nombre, stock FROM productos WHERE id=?");
        $row->execute([$id_prod]);
        $prod = $row->fetch();
        if ($prod && $cantidad <= (int)$prod['stock']) {
            $pdo->beginTransaction();
            // stock>=? evita dejar stock negativo si dos personas registran al mismo tiempo
            $upd = $pdo->prepare("UPDATE productos SET stock=stock-? WHERE id=? AND stock>=?");
            $upd->execute([$cantidad, $id_prod, $cantidad]);
            if ($upd->rowCount() === 0) { $pdo->rollBack(); flash_redirect('averiados.php', "Stock insuficiente.", 'danger'); }
            $pdo->prepare("INSERT INTO inventario_movimientos (id_producto,tipo,cantidad,motivo,id_usuario) VALUES (?,?,?,?,?)")
                ->execute([$id_prod, $tipo, $cantidad, $motivo, $_SESSION['id_usuario']]);
            $pdo->commit();
            $msg = "Registrado: {$cantidad} ud. de «{$prod['nombre']}» como {$tipo}.";
        } elseif ($prod) {
            $msg = "Stock insuficiente. Disponible: {$prod['stock']}."; $msg_type = 'danger';
        } else { $msg = "Producto no encontrado."; $msg_type = 'danger'; }
    } else { $msg = "Producto y motivo son obligatorios."; $msg_type = 'danger'; }
    flash_redirect('averiados.php', $msg, $msg_type);
}
[$msg, $msg_type] = flash_get();

$productos   = $pdo->query("SELECT id, nombre, color, stock FROM productos ORDER BY nombre, color")->fetchAll();
$movimientos = $pdo->query("SELECT m.*, p.nombre as producto, p.color, p.precio_compra, u.nombre as usuario
    FROM inventario_movimientos m
    JOIN productos p ON p.id=m.id_producto
    LEFT JOIN usuarios u ON u.id=m.id_usuario
    ORDER BY m.fecha DESC LIMIT 60")->fetchAll();

// Resumen del mes: unidades y costo (a precio de compra actual) por tipo
$resumen = ['Obsequio' => ['und' => 0, 'costo' => 0], 'Averiado' => ['und' => 0, 'costo' => 0]];
foreach ($pdo->query("SELECT m.tipo, SUM(m.cantidad) und, SUM(m.cantidad * IFNULL(p.precio_compra,0)) costo
    FROM inventario_movimientos m LEFT JOIN productos p ON p.id=m.id_producto
    WHERE YEAR(m.fecha)=YEAR(CURDATE()) AND MONTH(m.fecha)=MONTH(CURDATE()) GROUP BY m.tipo") as $r) {
    $resumen[$r['tipo']] = ['und' => (int)$r['und'], 'costo' => (float)$r['costo']];
}
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Averiados / Obsequios</h1><p>Registra salidas de inventario no comerciales</p></div>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1"><i class="fa-solid fa-gift me-1" style="color:#db2777"></i>Obsequios este mes</div>
        <div class="stat-value" style="font-size:1.3rem;color:#be185d"><?= $resumen['Obsequio']['und'] ?> und.</div>
        <div class="small text-muted">Costo: $<?= number_format($resumen['Obsequio']['costo'],2) ?></div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1"><i class="fa-solid fa-heart-crack me-1 text-danger"></i>Averiados este mes</div>
        <div class="stat-value text-danger" style="font-size:1.3rem"><?= $resumen['Averiado']['und'] ?> und.</div>
        <div class="small text-muted">Costo: $<?= number_format($resumen['Averiado']['costo'],2) ?></div>
    </div></div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700">Registrar Movimiento</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Producto</label>
                    <select name="id_producto" class="form-select" required>
                        <option value="">Seleccione...</option>
                        <?php foreach($productos as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= h(producto_label($p['nombre'], $p['color'])) ?> — <?= $p['stock'] ?> und.</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" required>
                        <option value="Averiado">Averiado / Dañado</option>
                        <option value="Obsequio">Obsequio / Regalo</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="cantidad" class="form-control" min="1" value="1" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Motivo detallado</label>
                    <textarea name="motivo" class="form-control" rows="3" placeholder="Ej. Pantalla rota, obsequio a cliente VIP..." required></textarea>
                </div>
                <button type="submit" name="registrar" class="btn btn-danger w-100">
                    <i class="fa-solid fa-circle-minus me-2"></i>Registrar Salida
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header-custom">
                <h6 class="mb-0 fw-700" style="font-weight:700">Historial de Movimientos</h6>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th>Cant.</th><th>Costo</th><th>Motivo</th><th>Usuario</th></tr></thead>
                    <tbody>
                        <?php if(empty($movimientos)): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-box-open"></i>Sin movimientos</div></td></tr>
                        <?php else: foreach($movimientos as $m): ?>
                        <tr>
                            <td style="font-size:.8rem"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                            <td class="fw-600" style="font-weight:600;font-size:.875rem"><?= h($m['producto']) ?>
                                <?php if ($m['color']): ?><div><?= color_badge($m['color']) ?></div><?php endif; ?></td>
                            <td>
                                <span class="badge <?= $m['tipo']==='Averiado' ? 'badge-danger' : 'badge-gift' ?>">
                                    <?= $m['tipo'] ?>
                                </span>
                            </td>
                            <td><?= $m['cantidad'] ?></td>
                            <td style="font-size:.82rem;color:#64748b">$<?= number_format($m['cantidad'] * (float)$m['precio_compra'],2) ?></td>
                            <td style="font-size:.82rem"><?php if ($m['id_venta']): ?><a href="ticket.php?id=<?= (int)$m['id_venta'] ?>" target="_blank"><i class="fa-solid fa-receipt me-1"></i>Venta #<?= (int)$m['id_venta'] ?></a><?php else: ?><?= h(mb_strimwidth($m['motivo'], 0, 50, '…')) ?><?php endif; ?></td>
                            <td style="font-size:.78rem;color:#64748b"><?= h($m['usuario'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
