<?php
require 'config/conexion.php';

// Add
if (isset($_POST['guardar_cliente'])) {
    csrf_verify();
    $nombre = trim($_POST['nombre']); $doc = trim($_POST['documento']);
    $tel = trim($_POST['telefono']); $email = trim($_POST['email']);
    if ($nombre && $doc) {
        try {
            $pdo->prepare("INSERT INTO clientes (nombre,documento,telefono,email) VALUES (?,?,?,?)")->execute([$nombre,$doc,$tel,$email]);
            flash_redirect('clientes.php', "Cliente «{$nombre}» guardado.");
        } catch (PDOException $e) { flash_redirect('clientes.php', "El documento ya está registrado.", 'danger'); }
    }
    flash_redirect('clientes.php', "Nombre y documento son obligatorios.", 'danger');
}

// Edit
if (isset($_POST['editar_cliente'])) {
    csrf_verify();
    $id = (int)$_POST['id']; $nombre = trim($_POST['nombre']); $doc = trim($_POST['documento']);
    $tel = trim($_POST['telefono']); $email = trim($_POST['email']);
    if ($nombre && $doc) {
        try {
            $pdo->prepare("UPDATE clientes SET nombre=?,documento=?,telefono=?,email=? WHERE id=?")->execute([$nombre,$doc,$tel,$email,$id]);
            flash_redirect('clientes.php', "Cliente actualizado.");
        } catch (PDOException $e) { flash_redirect('clientes.php', "El documento ya está en uso.", 'danger'); }
    }
    flash_redirect('clientes.php', "Nombre y documento son obligatorios.", 'danger');
}

// Delete
if (isset($_POST['eliminar'])) {
    csrf_verify();
    $id = (int)$_POST['eliminar'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE id_cliente=?");
    $st->execute([$id]);
    $ventas = (int)$st->fetchColumn();
    if ($ventas > 0) {
        flash_redirect('clientes.php', "No se puede eliminar: el cliente tiene {$ventas} ventas registradas.", 'danger');
    } elseif ($id == 1) {
        flash_redirect('clientes.php', "El cliente general no se puede eliminar.", 'warning');
    }
    $pdo->prepare("DELETE FROM clientes WHERE id=?")->execute([$id]);
    flash_redirect('clientes.php', "Cliente eliminado.");
}
[$msg, $msg_type] = flash_get();

$buscar = trim($_GET['q'] ?? '');
$sql = "SELECT c.*, (SELECT COUNT(*) FROM ventas v WHERE v.id_cliente=c.id) as total_ventas FROM clientes c WHERE 1=1";
$params = [];
if ($buscar !== '') {
    $sql .= " AND (c.nombre LIKE ? OR c.documento LIKE ? OR c.telefono LIKE ?)";
    $params = ["%$buscar%", "%$buscar%", "%$buscar%"];
}
$sql .= " ORDER BY c.nombre";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$clientes = $stmt->fetchAll();
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Clientes</h1><p>Directorio de clientes registrados</p></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregar">
        <i class="fa-solid fa-plus me-1"></i>Nuevo Cliente
    </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="card-header-custom d-flex align-items-center gap-3">
        <form method="GET" class="d-flex gap-2 flex-fill">
            <input type="text" name="q" class="form-control" placeholder="Buscar por nombre, documento o teléfono..." value="<?= h($buscar) ?>">
            <button type="submit" class="btn btn-outline-secondary">Buscar</button>
            <?php if($buscar): ?><a href="clientes.php" class="btn btn-outline-secondary">✕</a><?php endif; ?>
        </form>
        <span class="text-muted small"><?= count($clientes) ?> clientes</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>Nombre</th><th>Documento</th><th>Teléfono</th><th>Email</th><th>Ventas</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php if(empty($clientes)): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-users"></i>No se encontraron clientes</div></td></tr>
                <?php else: foreach($clientes as $c): ?>
                <tr>
                    <td class="fw-600" style="font-weight:600"><?= h($c['nombre']) ?></td>
                    <td style="font-family:monospace"><?= h($c['documento']) ?></td>
                    <td><?= h($c['telefono'] ?? '—') ?></td>
                    <td style="font-size:.82rem;color:#64748b"><?= h($c['email'] ?? '—') ?></td>
                    <td><span class="badge badge-info"><?= $c['total_ventas'] ?></span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-xs btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#modalEditar"
                                data-id="<?= $c['id'] ?>"
                                data-nombre="<?= h($c['nombre']) ?>"
                                data-documento="<?= h($c['documento']) ?>"
                                data-telefono="<?= h($c['telefono'] ?? '') ?>"
                                data-email="<?= h($c['email'] ?? '') ?>"
                                title="Editar"><i class="fa-solid fa-pen"></i></button>
                            <?php if($c['id'] > 1): ?>
                            <?= delete_button((int)$c['id'], "¿Eliminar a «{$c['nombre']}»?") ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Agregar -->
<div class="modal fade" id="modalAgregar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-700" style="font-weight:700">Nuevo Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="modal-body pt-3">
                    <div class="mb-3"><label class="form-label">Nombre completo *</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Documento / NIT *</label><input type="text" name="documento" class="form-control" required></div>
                    <div class="row g-2">
                        <div class="col-6 mb-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control"></div>
                        <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_cliente" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-700" style="font-weight:700">Editar Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="ec_id">
                <div class="modal-body pt-3">
                    <div class="mb-3"><label class="form-label">Nombre completo *</label><input type="text" name="nombre" id="ec_nombre" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Documento / NIT *</label><input type="text" name="documento" id="ec_doc" class="form-control" required></div>
                    <div class="row g-2">
                        <div class="col-6 mb-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" id="ec_tel" class="form-control"></div>
                        <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" id="ec_email" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_cliente" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('modalEditar').addEventListener('show.bs.modal', function(e) {
    const b = e.relatedTarget;
    document.getElementById('ec_id').value     = b.dataset.id;
    document.getElementById('ec_nombre').value = b.dataset.nombre;
    document.getElementById('ec_doc').value    = b.dataset.documento;
    document.getElementById('ec_tel').value    = b.dataset.telefono;
    document.getElementById('ec_email').value  = b.dataset.email;
});
</script>
<?php include 'includes/footer.php'; ?>
