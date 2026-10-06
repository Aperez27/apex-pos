<?php
require 'config/conexion.php';
if ($rol !== 'admin') { include 'includes/header.php'; echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }

if (isset($_POST['guardar_proveedor'])) {
    csrf_verify();
    $nombre = trim($_POST['nombre']); $contacto = trim($_POST['contacto']);
    $tel = trim($_POST['telefono']); $email = trim($_POST['email']); $obs = trim($_POST['observacion'] ?? '');
    if ($nombre) {
        $pdo->prepare("INSERT INTO proveedores (nombre,contacto,telefono,email,observacion) VALUES (?,?,?,?,?)")->execute([$nombre,$contacto,$tel,$email,$obs ?: null]);
        flash_redirect('proveedores.php', "Proveedor «{$nombre}» guardado.");
    }
    flash_redirect('proveedores.php', "El nombre es obligatorio.", 'danger');
}

if (isset($_POST['editar_proveedor'])) {
    csrf_verify();
    $id = (int)$_POST['id']; $nombre = trim($_POST['nombre']);
    $contacto = trim($_POST['contacto']); $tel = trim($_POST['telefono']); $email = trim($_POST['email']);
    $obs = trim($_POST['observacion'] ?? '');
    if ($nombre === '') flash_redirect('proveedores.php', "El nombre es obligatorio.", 'danger');
    $pdo->prepare("UPDATE proveedores SET nombre=?,contacto=?,telefono=?,email=?,observacion=? WHERE id=?")->execute([$nombre,$contacto,$tel,$email,$obs ?: null,$id]);
    flash_redirect('proveedores.php', "Proveedor actualizado.");
}

if (isset($_POST['eliminar'])) {
    csrf_verify();
    $id = (int)$_POST['eliminar'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE id_proveedor=?");
    $st->execute([$id]);
    $prods = (int)$st->fetchColumn();
    if ($prods > 0) {
        flash_redirect('proveedores.php', "No se puede eliminar: tiene {$prods} producto(s) asociados.", 'danger');
    }
    $pdo->prepare("DELETE FROM proveedores WHERE id=?")->execute([$id]);
    flash_redirect('proveedores.php', "Proveedor eliminado.");
}
[$msg, $msg_type] = flash_get();

$proveedores = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM productos WHERE id_proveedor=p.id) as total_prods FROM proveedores p ORDER BY p.nombre")->fetchAll();
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Proveedores</h1><p>Gestión de proveedores y contactos</p></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregar">
        <i class="fa-solid fa-plus me-1"></i>Nuevo Proveedor
    </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Nombre</th><th>Qué suministra</th><th>Contacto</th><th>Teléfono</th><th>Email</th><th>Productos</th><th>Acciones</th></tr></thead>
            <tbody>
                <?php if(empty($proveedores)): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fa-solid fa-truck"></i>No hay proveedores</div></td></tr>
                <?php else: foreach($proveedores as $p): ?>
                <tr>
                    <td class="fw-600" style="font-weight:600"><?= h($p['nombre']) ?></td>
                    <td style="font-size:.82rem;max-width:280px;white-space:pre-line"><?= trim((string)$p['observacion']) !== '' ? h($p['observacion']) : '<span class="text-muted">—</span>' ?></td>
                    <td><?= h($p['contacto'] ?? '—') ?></td>
                    <td><?= h($p['telefono'] ?? '—') ?></td>
                    <td style="font-size:.82rem;color:#64748b"><?= h($p['email'] ?? '—') ?></td>
                    <td><span class="badge badge-info"><?= $p['total_prods'] ?></span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <button class="btn btn-xs btn-outline-primary"
                                data-bs-toggle="modal" data-bs-target="#modalEditar"
                                data-id="<?= $p['id'] ?>"
                                data-nombre="<?= h($p['nombre']) ?>"
                                data-contacto="<?= h($p['contacto'] ?? '') ?>"
                                data-telefono="<?= h($p['telefono'] ?? '') ?>"
                                data-email="<?= h($p['email'] ?? '') ?>"
                                data-observacion="<?= h($p['observacion'] ?? '') ?>"
                                title="Editar"><i class="fa-solid fa-pen"></i></button>
                            <?= delete_button((int)$p['id'], "¿Eliminar proveedor «{$p['nombre']}»?") ?>
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
    <div class="modal-dialog"><div class="modal-content" style="border-radius:16px">
        <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-700" style="font-weight:700">Nuevo Proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="modal-body pt-3">
                <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Contacto</label><input type="text" name="contacto" class="form-control"></div>
                <div class="row g-2">
                    <div class="col-6 mb-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control"></div>
                    <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
                </div>
                <div class="mb-1"><label class="form-label">Observación / qué suministra</label><textarea name="observacion" class="form-control" rows="2" placeholder="Ej. Vidrios templados y protectores de pantalla"></textarea></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="guardar_proveedor" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Modal Editar -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content" style="border-radius:16px">
        <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-700" style="font-weight:700">Editar Proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="ep_id">
            <div class="modal-body pt-3">
                <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" id="ep_nombre" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Contacto</label><input type="text" name="contacto" id="ep_contacto" class="form-control"></div>
                <div class="row g-2">
                    <div class="col-6 mb-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" id="ep_tel" class="form-control"></div>
                    <div class="col-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" id="ep_email" class="form-control"></div>
                </div>
                <div class="mb-1"><label class="form-label">Observación / qué suministra</label><textarea name="observacion" id="ep_obs" class="form-control" rows="2" placeholder="Ej. Vidrios templados y protectores de pantalla"></textarea></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="editar_proveedor" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div></div>
</div>

<script>
document.getElementById('modalEditar').addEventListener('show.bs.modal', function(e) {
    const b = e.relatedTarget;
    document.getElementById('ep_id').value       = b.dataset.id;
    document.getElementById('ep_nombre').value   = b.dataset.nombre;
    document.getElementById('ep_contacto').value = b.dataset.contacto;
    document.getElementById('ep_tel').value      = b.dataset.telefono;
    document.getElementById('ep_email').value    = b.dataset.email;
    document.getElementById('ep_obs').value      = b.dataset.observacion;
});
</script>
<?php include 'includes/footer.php'; ?>
