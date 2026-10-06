<?php
require 'config/conexion.php';

$st = $pdo->prepare("SELECT usuario, password, debe_cambiar_clave FROM usuarios WHERE id=?");
$st->execute([$_SESSION['id_usuario']]);
$u = $st->fetch();
$obligatorio = !empty($u['debe_cambiar_clave']);

$error = '';
if (isset($_POST['cambiar'])) {
    csrf_verify();
    $actual = (string)($_POST['actual'] ?? '');
    $nueva  = (string)($_POST['nueva'] ?? '');
    $repite = (string)($_POST['repite'] ?? '');
    if (!password_verify($actual, $u['password'])) {
        $error = 'La contraseña actual no es correcta.';
    } elseif (mb_strlen($nueva) < PASSWORD_MIN) {
        $error = 'La nueva contraseña debe tener al menos ' . PASSWORD_MIN . ' caracteres.';
    } elseif ($nueva !== $repite) {
        $error = 'Las dos contraseñas nuevas no coinciden.';
    } elseif ($nueva === $actual || $nueva === $u['usuario'] . '123' || strcasecmp($nueva, $u['usuario']) === 0) {
        $error = 'Elige una contraseña distinta a la actual, a la inicial y a tu usuario.';
    } else {
        $pdo->prepare("UPDATE usuarios SET password=?, debe_cambiar_clave=0 WHERE id=?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $_SESSION['id_usuario']]);
        session_regenerate_id(true);
        flash_redirect('dashboard.php', 'Contraseña actualizada.');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Cambiar contraseña · <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { width: 100%; max-width: 440px; padding: 1rem; }
        .tarjeta { background: #fff; border-radius: 20px; padding: 2.2rem; box-shadow: 0 25px 60px rgba(0,0,0,.35); }
        .logo { width: 52px; height: 52px; background: linear-gradient(135deg,#3b82f6,#8b5cf6); border-radius: 15px; display: flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; margin: 0 auto 1rem; }
        h1 { font-size: 1.25rem; font-weight: 800; text-align: center; color: #0f172a; }
        .form-control { border-radius: 10px; padding: .65rem .9rem; }
        .form-label { font-size: .8rem; font-weight: 600; color: #374151; }
        .btn-dark { border-radius: 10px; padding: .75rem; font-weight: 700; }
        .copy { text-align: center; margin-top: 1.1rem; font-size: .72rem; color: rgba(226,232,240,.6); }
    </style>
</head>
<body>
<div class="wrap">
    <div class="tarjeta">
        <div class="logo">AP</div>
        <h1>Cambiar contraseña</h1>
        <?php if ($obligatorio): ?>
        <div class="alert alert-warning py-2 small">Por seguridad debes cambiar la contraseña inicial antes de usar el sistema.</div>
        <?php else: ?>
        <p class="text-center text-muted small">Usuario: <b><?= h($u['usuario']) ?></b></p>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger py-2 small"><?= h($error) ?></div><?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= csrf_field() ?>
            <div class="mb-3"><label class="form-label">Contraseña actual</label><input type="password" name="actual" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Nueva contraseña (mín. <?= PASSWORD_MIN ?> caracteres)</label><input type="password" name="nueva" class="form-control" minlength="<?= PASSWORD_MIN ?>" required></div>
            <div class="mb-4"><label class="form-label">Repite la nueva contraseña</label><input type="password" name="repite" class="form-control" minlength="<?= PASSWORD_MIN ?>" required></div>
            <button type="submit" name="cambiar" class="btn btn-dark w-100">Guardar contraseña</button>
        </form>
        <div class="text-center mt-3 small">
            <?php if ($obligatorio): ?><a href="logout.php" class="text-muted">Cerrar sesión</a>
            <?php else: ?><a href="dashboard.php" class="text-muted">← Volver</a><?php endif; ?>
        </div>
    </div>
    <p class="copy"><?= h(app_copyright()) ?></p>
</div>
</body>
</html>
