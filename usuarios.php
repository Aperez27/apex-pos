<?php
require 'config/conexion.php';
if ($rol !== 'admin') { include 'includes/header.php'; echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }

if (isset($_POST['crear_usuario'])) {
    csrf_verify();
    $nombre  = trim($_POST['nombre']);
    $usuario = trim($_POST['usuario']);
    $pass    = $_POST['password'];
    $rolusr  = in_array($_POST['rol'] ?? '', ['admin','vendedor'], true) ? $_POST['rol'] : 'vendedor';
    if ($nombre && $usuario && mb_strlen($pass) >= PASSWORD_MIN) {
        try {
            $pdo->prepare("INSERT INTO usuarios (nombre,usuario,password,rol) VALUES (?,?,?,?)")
                ->execute([$nombre, $usuario, password_hash($pass, PASSWORD_DEFAULT), $rolusr]);
            flash_redirect('usuarios.php', "Usuario «{$usuario}» creado.");
        } catch (PDOException $e) { flash_redirect('usuarios.php', "El nombre de usuario ya existe.", 'danger'); }
    }
    flash_redirect('usuarios.php', "Todos los campos son requeridos y la contraseña debe tener al menos " . PASSWORD_MIN . " caracteres.", 'danger');
}

if (isset($_POST['cambiar_pass'])) {
    csrf_verify();
    $id   = (int)$_POST['id_usuario'];
    $pass = $_POST['nueva_password'];
    if (mb_strlen($pass) >= PASSWORD_MIN) {
        $pdo->prepare("UPDATE usuarios SET password=? WHERE id=?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        flash_redirect('usuarios.php', "Contraseña actualizada.");
    }
    flash_redirect('usuarios.php', "La contraseña debe tener al menos " . PASSWORD_MIN . " caracteres.", 'danger');
}

if (isset($_POST['eliminar'])) {
    csrf_verify();
    $id = (int)$_POST['eliminar'];
    if ($id === (int)$_SESSION['id_usuario']) {
        flash_redirect('usuarios.php', "No puedes eliminar tu propia cuenta.", 'danger');
    }
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM ventas WHERE id_usuario=?) + (SELECT COUNT(*) FROM abonos WHERE id_usuario=?)
        + (SELECT COUNT(*) FROM cajas WHERE id_usuario_apertura=? OR id_usuario_cierre=?) + (SELECT COUNT(*) FROM caja_chica WHERE id_usuario=?)");
    $st->execute([$id, $id, $id, $id, $id]);
    if ((int)$st->fetchColumn() > 0) {
        flash_redirect('usuarios.php', "No se puede eliminar: el usuario tiene ventas, abonos o movimientos de caja registrados. Cámbiale la contraseña para bloquear su acceso.", 'danger');
    }
    $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
    flash_redirect('usuarios.php', "Usuario eliminado.");
}
[$msg, $msg_type] = flash_get();

$usuarios = $pdo->query("SELECT * FROM usuarios ORDER BY rol DESC, nombre")->fetchAll();
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Usuarios</h1><p>Control de accesos y roles del sistema</p></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrear">
        <i class="fa-solid fa-plus me-1"></i>Nuevo Usuario
    </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Cambiar Contraseña</th><th>Acciones</th></tr></thead>
            <tbody>
                <?php foreach($usuarios as $u): ?>
                <tr>
                    <td class="fw-600" style="font-weight:600"><?= h($u['nombre']) ?></td>
                    <td style="font-family:monospace;font-size:.85rem"><?= h($u['usuario']) ?></td>
                    <td>
                        <span class="badge <?= $u['rol']==='admin' ? 'badge-info' : 'badge-secondary' ?>">
                            <?= strtoupper($u['rol']) ?>
                        </span>
                    </td>
                    <td>
                        <form method="POST" class="d-flex gap-2 align-items-center">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_usuario" value="<?= $u['id'] ?>">
                            <input type="password" name="nueva_password" class="form-control form-control-sm" placeholder="Mín. <?= PASSWORD_MIN ?> caracteres" minlength="<?= PASSWORD_MIN ?>" style="max-width:170px" required>
                            <button type="submit" name="cambiar_pass" class="btn btn-sm btn-outline-primary">
                                <i class="fa-solid fa-key me-1"></i>Cambiar
                            </button>
                        </form>
                    </td>
                    <td>
                        <?php if($u['id'] != (int)$_SESSION['id_usuario']): ?>
                        <?= delete_button((int)$u['id'], "¿Eliminar al usuario «{$u['usuario']}»?") ?>
                        <?php else: ?>
                        <span class="badge badge-secondary">Tú</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Crear -->
<div class="modal fade" id="modalCrear" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content" style="border-radius:16px">
        <div class="modal-header border-0 pb-0"><h5 class="modal-title fw-700" style="font-weight:700">Nuevo Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <div class="modal-body pt-3">
                <div class="mb-3"><label class="form-label">Nombre Completo *</label><input type="text" name="nombre" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Usuario (login) *</label><input type="text" name="usuario" class="form-control" autocomplete="off" required></div>
                <div class="mb-3"><label class="form-label">Contraseña * (mín. <?= PASSWORD_MIN ?> caracteres)</label><input type="password" name="password" class="form-control" minlength="<?= PASSWORD_MIN ?>" required></div>
                <div class="mb-3">
                    <label class="form-label">Rol</label>
                    <select name="rol" class="form-select">
                        <option value="vendedor">Vendedor</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="crear_usuario" class="btn btn-primary">Crear Usuario</button>
            </div>
        </form>
    </div></div>
</div>
<?php include 'includes/footer.php'; ?>
