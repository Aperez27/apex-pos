<?php
// Se incluye después de config/conexion.php (sesión, $rol y $pdo ya disponibles)
if (!isset($_SESSION['usuario'])) { header("Location: index.php"); exit; }
$current = basename($_SERVER['PHP_SELF']);
$pageTitles = [
    'dashboard.php'    => 'Dashboard',
    'ventas.php'       => 'Punto de Venta',
    'abonos.php'       => 'Cartera',
    'clientes.php'     => 'Clientes',
    'proveedores.php'  => 'Proveedores',
    'productos.php'    => 'Inventario',
    'averiados.php'    => 'Averiados / Obsequios',
    'caja.php'         => 'Control de Caja',
    'reportes.php'     => 'Reportes',
    'usuarios.php'     => 'Usuarios',
    'backup.php'       => 'Backup',
    'configuracion.php'=> 'Configuración',
];
$topTitle = $pageTitles[$current] ?? APP_NAME;
function nav_link(string $href, string $icon, string $label, string $current): string {
    $active = basename($href) === $current ? 'active' : '';
    return "<a href=\"{$href}\" class=\"nav-link-item {$active}\"><i class=\"{$icon}\"></i> {$label}</a>";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($topTitle) ?> · <?= APP_NAME ?></title>
    <meta name="author" content="<?= h(APP_AUTHOR) ?>">
    <meta name="robots" content="noindex, nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-w: 252px;
            --sidebar-bg: #0f172a;
            --sidebar-text: rgba(226,232,240,.78);
            --sidebar-active-bg: rgba(59,130,246,.15);
            --sidebar-active: #60a5fa;
            --sidebar-hover: rgba(255,255,255,.055);
            --topbar-h: 58px;
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #1e293b;
            --muted: #64748b;
            --border: #e2e8f0;
            --radius: 12px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; background: var(--bg); color: var(--text); overflow-x: hidden; }

        /* ── Sidebar ── */
        .sidebar {
            width: var(--sidebar-w); min-height: 100vh;
            background: var(--sidebar-bg);
            position: fixed; top: 0; left: 0; z-index: 1000;
            display: flex; flex-direction: column;
            transition: transform .28s ease; overflow-y: auto;
        }
        .sidebar-brand {
            padding: 1.2rem 1.4rem; display: flex; align-items: center; gap: .7rem;
            border-bottom: 1px solid rgba(255,255,255,.07); text-decoration: none; flex-shrink: 0;
        }
        .brand-icon {
            width: 36px; height: 36px; background: #3b82f6; border-radius: 9px;
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: .9rem; flex-shrink: 0;
        }
        .brand-text { color: #f8fafc; font-weight: 700; font-size: .95rem; line-height: 1.2; }
        .brand-text small { color: #94a3b8; font-size: .68rem; font-weight: 400; display: block; }

        .sidebar-nav { flex: 1; padding: .85rem .65rem; }
        .nav-section {
            font-size: .62rem; letter-spacing: .1em; text-transform: uppercase;
            color: rgba(148,163,184,.45); padding: .7rem .6rem .3rem; font-weight: 700;
        }
        .nav-link-item {
            display: flex; align-items: center; gap: .65rem;
            padding: .58rem .85rem; color: var(--sidebar-text);
            text-decoration: none; border-radius: 8px;
            font-size: .845rem; font-weight: 500;
            transition: background .14s, color .14s; margin-bottom: 2px;
        }
        .nav-link-item i { width: 17px; text-align: center; font-size: .82rem; opacity: .8; }
        .nav-link-item:hover { background: var(--sidebar-hover); color: #fff; }
        .nav-link-item:hover i { opacity: 1; }
        .nav-link-item.active { background: var(--sidebar-active-bg); color: var(--sidebar-active); font-weight: 600; }
        .nav-link-item.active i { opacity: 1; }

        .sidebar-footer {
            padding: .9rem 1.1rem; border-top: 1px solid rgba(255,255,255,.07);
            display: flex; align-items: center; gap: .65rem; flex-shrink: 0;
        }
        .user-ava {
            width: 30px; height: 30px; border-radius: 50%; background: #3b82f6;
            display: flex; align-items: center; justify-content: center;
            font-size: .75rem; color: #fff; font-weight: 700; flex-shrink: 0;
        }
        .user-name { color: #e2e8f0; font-size: .78rem; font-weight: 600; line-height: 1.2; }
        .user-role { color: rgba(148,163,184,.65); font-size: .67rem; }
        .logout-link { margin-left: auto; color: rgba(148,163,184,.5); font-size: .85rem; text-decoration: none; transition: color .15s; }
        .logout-link:hover { color: #ef4444; }

        /* ── Main ── */
        .main-wrapper { margin-left: var(--sidebar-w); display: flex; flex-direction: column; min-height: 100vh; }
        .topbar {
            height: var(--topbar-h); background: #fff; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; padding: 0 1.5rem; gap: .9rem;
            position: sticky; top: 0; z-index: 100;
        }
        .topbar-toggle { display: none; background: none; border: none; padding: 0; font-size: 1.1rem; color: var(--muted); cursor: pointer; }
        .topbar-title { font-weight: 700; font-size: .95rem; color: var(--text); }
        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: .85rem; }
        .topbar-clock { font-size: .78rem; font-family: 'SF Mono','Fira Code',monospace; color: var(--muted); background: var(--bg); padding: .28rem .72rem; border-radius: 20px; border: 1px solid var(--border); }
        .content-area { padding: 1.5rem; flex: 1; }

        /* ── Cards ── */
        .card { border: 1px solid var(--border) !important; border-radius: var(--radius) !important; box-shadow: 0 1px 3px rgba(0,0,0,.05) !important; background: var(--card); }
        .card-header-custom { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); }

        /* ── Stats ── */
        .stat-card { padding: 1.2rem 1.4rem; border-radius: var(--radius); background: #fff; border: 1px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
        .stat-value { font-size: 1.55rem; font-weight: 800; line-height: 1.1; letter-spacing: -.02em; }
        .stat-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 600; }

        /* ── Tables ── */
        .table { border-collapse: separate; border-spacing: 0; }
        .table th { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; color: var(--muted); border-bottom: 2px solid var(--border) !important; padding: .7rem 1rem; background: #f8fafc; white-space: nowrap; }
        .table td { padding: .8rem 1rem; border-bottom: 1px solid #f1f5f9 !important; vertical-align: middle; font-size: .875rem; }
        .table tbody tr:last-child td { border-bottom: none !important; }
        .table tbody tr:hover { background: #f8fafc; }

        /* ── Forms ── */
        .form-control, .form-select { border: 1px solid #d1d5db; border-radius: 8px; padding: .6rem .85rem; font-size: .875rem; transition: border-color .15s, box-shadow .15s; }
        .form-control:focus, .form-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.11); }
        .form-label { font-size: .8rem; font-weight: 600; color: #374151; margin-bottom: .3rem; }
        .input-group-text { border-radius: 8px 0 0 8px !important; background: #f9fafb; border: 1px solid #d1d5db; font-size: .875rem; }

        /* ── Buttons ── */
        .btn { border-radius: 8px; font-weight: 600; font-size: .85rem; padding: .52rem 1.05rem; transition: all .15s ease; }
        .btn-primary { background: #3b82f6; border-color: #3b82f6; }
        .btn-primary:hover { background: #2563eb; border-color: #2563eb; }
        .btn-sm { padding: .32rem .7rem; font-size: .78rem; }
        .btn-xs { padding: .22rem .5rem; font-size: .74rem; border-radius: 6px; font-weight: 600; }

        /* ── Badges ── */
        .badge { font-weight: 600; border-radius: 6px; font-size: .72rem; }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-warning { background: #fef9c3; color: #a16207; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #dbeafe; color: #1d4ed8; }
        .badge-secondary { background: #f1f5f9; color: #475569; }
        .badge-dark { background: #1e293b; color: #e2e8f0; }

        /* ── Page header ── */
        .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.4rem; flex-wrap: wrap; gap: .75rem; }
        .page-header h1 { font-size: 1.35rem; font-weight: 800; color: var(--text); margin: 0; }
        .page-header p { font-size: .8rem; color: var(--muted); margin: .15rem 0 0; }

        /* ── Alert ── */
        .alert { border-radius: 10px; border: none; font-size: .86rem; }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 2.5rem 1rem; color: var(--muted); }
        .empty-state i { font-size: 2rem; opacity: .3; display: block; margin-bottom: .75rem; }

        /* ── Overlay for mobile ── */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 999; }
        .sidebar-overlay.open { display: block; }

        @media (max-width: 1024px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .topbar-toggle { display: block; }
        }
        @media (max-width: 640px) {
            .content-area { padding: 1rem .75rem; }
            .stat-value { font-size: 1.25rem; }
        }
        .brand-mono { background: linear-gradient(135deg,#3b82f6,#8b5cf6); font-weight: 800; font-size: .82rem; letter-spacing: .02em; }
        .brand-text { letter-spacing: .12em; }
        .brand-text small { letter-spacing: .02em; }
        /* ── Colores de producto ── */
        .color-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; border: 1px solid rgba(15,23,42,.25); vertical-align: -1px; flex-shrink: 0; }
        .color-chip { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; white-space: nowrap; }
        .badge-gift { background: #fce7f3; color: #be185d; }
        .app-footer { padding: .9rem 1.5rem; border-top: 1px solid var(--border); font-size: .72rem; color: var(--muted); text-align: center; background: #fff; }
        .metodo-abono .btn-check:checked + .btn { background: #0f172a; border-color: #0f172a; color: #fff; }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
    <a href="dashboard.php" class="sidebar-brand">
        <div class="brand-icon brand-mono">AP</div>
        <div class="brand-text"><?= APP_NAME ?> <small>by <?= h(APP_AUTHOR) ?></small></div>
    </a>

    <nav class="sidebar-nav">
        <div class="nav-section">Principal</div>
        <?= nav_link('dashboard.php', 'fa-solid fa-chart-line', 'Dashboard', $current) ?>
        <?= nav_link('ventas.php', 'fa-solid fa-cash-register', 'Punto de Venta', $current) ?>
        <?= nav_link('abonos.php', 'fa-solid fa-hand-holding-dollar', 'Cartera', $current) ?>
        <?= nav_link('caja.php', 'fa-solid fa-vault', 'Caja', $current) ?>

        <div class="nav-section">Gestión</div>
        <?= nav_link('clientes.php', 'fa-solid fa-users', 'Clientes', $current) ?>

        <?php if($rol === 'admin'): ?>
        <?= nav_link('proveedores.php', 'fa-solid fa-truck', 'Proveedores', $current) ?>
        <?= nav_link('productos.php', 'fa-solid fa-box-archive', 'Inventario', $current) ?>
        <?= nav_link('averiados.php', 'fa-solid fa-gift', 'Averiados / Obsequios', $current) ?>

        <div class="nav-section">Administración</div>
        <?= nav_link('reportes.php', 'fa-solid fa-file-export', 'Reportes', $current) ?>
        <?= nav_link('usuarios.php', 'fa-solid fa-user-shield', 'Usuarios', $current) ?>
        <?= nav_link('backup.php', 'fa-solid fa-database', 'Backup DB', $current) ?>
        <?= nav_link('configuracion.php', 'fa-solid fa-gear', 'Configuración', $current) ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="user-ava"><?= strtoupper(substr($_SESSION['nombre'], 0, 1)) ?></div>
        <div>
            <div class="user-name"><?= h($_SESSION['nombre']) ?></div>
            <div class="user-role"><?= ucfirst($rol) ?></div>
        </div>
        <a href="cambiar_clave.php" class="logout-link" title="Cambiar mi contraseña"><i class="fa-solid fa-key"></i></a>
        <a href="logout.php" class="logout-link" style="margin-left:.6rem" title="Cerrar sesión"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
</aside>

<div class="main-wrapper">
    <div class="topbar">
        <button class="topbar-toggle" onclick="openSidebar()"><i class="fa-solid fa-bars"></i></button>
        <span class="topbar-title"><?= h($topTitle) ?></span>
        <div class="topbar-right">
            <span class="topbar-clock" id="reloj">--:--:--</span>
        </div>
    </div>
    <div class="content-area">
