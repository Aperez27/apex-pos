<?php
require 'config/conexion.php';
if ($rol !== 'admin') { include 'includes/header.php'; echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }

if (isset($_POST['guardar'])) {
    csrf_verify();
    if (trim($_POST['nombre_empresa'] ?? '') === '') flash_redirect('configuracion.php', "El nombre de la empresa es obligatorio.", 'danger');
    $pdo->prepare("UPDATE configuracion SET nombre_empresa=?,nit=?,telefono=?,direccion=?,mensaje_ticket=? WHERE id=1")
        ->execute([trim($_POST['nombre_empresa']),trim($_POST['nit']),trim($_POST['telefono']),trim($_POST['direccion']),trim($_POST['mensaje_ticket'])]);
    flash_redirect('configuracion.php', "Configuración guardada.");
}
[$msg, $msg_type] = flash_get();

$conf = $pdo->query("SELECT * FROM configuracion WHERE id=1")->fetch();
if (!$conf) {
    $pdo->exec("INSERT INTO configuracion (id) VALUES (1)");
    $conf = $pdo->query("SELECT * FROM configuracion WHERE id=1")->fetch();
}

// ¿Algún usuario conserva la contraseña inicial del instalador?
$claves_iniciales = [];
foreach ($pdo->query("SELECT usuario, password FROM usuarios WHERE usuario IN ('admin','vendedor')") as $u) {
    if (password_verify($u['usuario'] . '123', $u['password'])) $claves_iniciales[] = $u['usuario'];
}
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Configuración</h1><p>Datos de la empresa y parámetros del sistema</p></div>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card p-4">
            <h6 class="fw-700 mb-4" style="font-weight:700">Datos de la Empresa</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-3"><label class="form-label">Nombre de la empresa</label><input type="text" name="nombre_empresa" class="form-control" value="<?= h($conf['nombre_empresa'] ?? '') ?>" required></div>
                <div class="row g-2">
                    <div class="col-sm-6 mb-3"><label class="form-label">NIT</label><input type="text" name="nit" class="form-control" value="<?= h($conf['nit'] ?? '') ?>"></div>
                    <div class="col-sm-6 mb-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= h($conf['telefono'] ?? '') ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?= h($conf['direccion'] ?? '') ?>"></div>
                <div class="mb-4"><label class="form-label">Mensaje pie de ticket</label><textarea name="mensaje_ticket" class="form-control" rows="3"><?= h($conf['mensaje_ticket'] ?? '') ?></textarea></div>
                <button type="submit" name="guardar" class="btn btn-primary px-4">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Guardar Configuración
                </button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700">Seguridad de accesos</h6>
            <?php if ($claves_iniciales): ?>
            <div class="alert alert-danger py-2 mb-0" style="font-size:.85rem"><i class="fa-solid fa-triangle-exclamation me-1"></i>
                <?= count($claves_iniciales) === 1 ? 'El usuario' : 'Los usuarios' ?> <b><?= h(implode(', ', $claves_iniciales)) ?></b> aún
                <?= count($claves_iniciales) === 1 ? 'tiene' : 'tienen' ?> la contraseña inicial del instalador. Cámbiala en <a href="usuarios.php">Usuarios</a>.</div>
            <?php else: ?>
            <div class="alert alert-success py-2 mb-0" style="font-size:.85rem"><i class="fa-solid fa-shield-halved me-1"></i>Ningún usuario usa la contraseña inicial del instalador.</div>
            <?php endif; ?>
            <p class="text-muted small mt-3 mb-0">Sistema <?= APP_NAME ?> v<?= APP_VERSION ?> · by <?= h(APP_AUTHOR) ?></p>
        </div>
        <div class="card p-4 mt-3">
            <h6 class="fw-700 mb-3" style="font-weight:700">Vista previa del ticket</h6>
            <div style="font-family:monospace;font-size:.78rem;background:#f8fafc;border-radius:8px;padding:1rem;border:1px dashed #d1d5db;text-align:center">
                <div class="fw-700" style="font-size:1rem"><?= h($conf['nombre_empresa'] ?? APP_NAME) ?></div>
                <div>NIT: <?= h($conf['nit'] ?? '—') ?></div>
                <div>Tel: <?= h($conf['telefono'] ?? '—') ?></div>
                <div><?= h($conf['direccion'] ?? '—') ?></div>
                <hr style="border:1px dashed #d1d5db;margin:.5rem 0">
                <div style="font-style:italic"><?= h($conf['mensaje_ticket'] ?? '') ?></div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
