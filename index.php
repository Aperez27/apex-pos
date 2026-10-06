<?php
require 'config/config.php';
app_session_start();
if (isset($_SESSION['usuario'])) { header("Location: dashboard.php"); exit; }

$pdo = null;
try { $pdo = app_pdo(); } catch (PDOException $e) { error_log('Login sin BD: ' . $e->getMessage()); }

// Protección contra fuerza bruta: 5 intentos fallidos por IP en 15 minutos bloquean el acceso un rato
const LOGIN_MAX_INTENTOS = 5;
const LOGIN_VENTANA_MIN  = 15;
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
function login_fallidos(PDO $pdo, string $ip): int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_intentos WHERE ip=? AND fecha > NOW() - INTERVAL " . LOGIN_VENTANA_MIN . " MINUTE");
        $st->execute([$ip]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login_fallidos($pdo, $ip) >= LOGIN_MAX_INTENTOS) {
        $error = 'Demasiados intentos fallidos. Espera ' . LOGIN_VENTANA_MIN . ' minutos e intenta de nuevo.';
    } elseif ($usuario !== '' && $password !== '') {
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        $row = $stmt->fetch();
        // Si el usuario no existe se verifica contra un hash ficticio: la respuesta tarda lo mismo (no revela usuarios)
        $ok = password_verify($password, $row['password'] ?? '$2y$10$68TrB2T34CXM1kdoPIau8uqZh4vMZaNn5fFaGWaKfQvMLRp6cnanq');
        if ($row && $ok) {
            try { $pdo->prepare("DELETE FROM login_intentos WHERE ip=? OR fecha < NOW() - INTERVAL 1 DAY")->execute([$ip]); } catch (PDOException $e) {}
            session_regenerate_id(true);
            $_SESSION['usuario']    = $row['usuario'];
            $_SESSION['nombre']     = $row['nombre'];
            $_SESSION['rol']        = $row['rol'];
            $_SESSION['id_usuario'] = $row['id'];
            $_SESSION['ultimo_uso'] = time();
            header("Location: dashboard.php"); exit;
        } else {
            try { $pdo->prepare("INSERT INTO login_intentos (ip, usuario) VALUES (?, ?)")->execute([$ip, mb_substr($usuario, 0, 50)]); } catch (PDOException $e) {}
            $error = 'Usuario o contraseña incorrectos.';
        }
    } else {
        $error = 'Complete todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> · Iniciar Sesión</title>
    <meta name="author" content="<?= htmlspecialchars(APP_AUTHOR, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui,-apple-system,"Segoe UI",sans-serif;
        }
        .login-wrap { width: 100%; max-width: 420px; padding: 1rem; }
        .login-card {
            background: #fff; border-radius: 20px; padding: 2.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,.35);
        }
        .logo-box {
            width: 56px; height: 56px; background: linear-gradient(135deg,#3b82f6,#8b5cf6); border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.35rem; font-weight: 800; letter-spacing: .02em; color: #fff; margin: 0 auto 1rem;
        }
        h1 { font-size: 1.5rem; font-weight: 800; color: #0f172a; text-align: center; letter-spacing: .18em; margin-bottom: .3rem; }
        .subtitle { text-align: center; color: #64748b; font-size: .85rem; margin-bottom: 2rem; }
        .subtitle .signature { display: block; margin-top: .2rem; font-size: .72rem; letter-spacing: .08em; color: #8b5cf6; font-weight: 600; }
        .form-control { border: 1px solid #e2e8f0; border-radius: 10px; padding: .7rem 1rem; font-size: .9rem; }
        .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .form-label { font-size: .8rem; font-weight: 600; color: #374151; }
        .input-icon { position: relative; }
        .input-icon i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .85rem; }
        .input-icon .form-control { padding-left: 2.3rem; }
        .btn-login { background: #0f172a; border: none; color: #fff; width: 100%; padding: .8rem; border-radius: 10px; font-size: .92rem; font-weight: 700; transition: background .2s; }
        .btn-login:hover { background: #1e293b; color: #fff; }
        .copy { text-align: center; margin-top: 1.25rem; font-size: .72rem; color: rgba(226,232,240,.6); }
        .alert { border-radius: 10px; border: none; font-size: .85rem; }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="logo-box">AP</div>
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Sistema Integral de Gestión<span class="signature">by <?= htmlspecialchars(APP_AUTHOR, ENT_QUOTES, 'UTF-8') ?></span></p>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['expirada']) && !$error): ?>
        <div class="alert alert-info py-2 small">Tu sesión se cerró por inactividad. Ingresa de nuevo.</div>
        <?php endif; ?>
        <?php if (!$pdo): ?>
        <div class="alert alert-warning py-2 small">Base de datos no disponible. Si es la primera instalación, <a href="setup.php">ejecuta el instalador</a>.</div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Usuario</label>
                <div class="input-icon">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="usuario" class="form-control" placeholder="Ingresa tu usuario" required>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Contraseña</label>
                <div class="input-icon">
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                </div>
            </div>
            <button type="submit" class="btn-login">Iniciar Sesión</button>
        </form>
    </div>
    <p class="copy"><?= htmlspecialchars(app_copyright(), ENT_QUOTES, 'UTF-8') ?></p>
</div>
</body>
</html>
