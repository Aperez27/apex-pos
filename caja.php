<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';
// Vendedor y administrador abren, cierran (arqueo) y ven reportes; ingresos/egresos manuales e historial global: solo administrador
$es_admin = $rol === 'admin';

if (isset($_POST['abrir_caja'])) {
    csrf_verify();
    [$ok, $m] = caja_abrir($pdo, (float)$_POST['monto_inicial'], trim($_POST['obs_apertura'] ?? ''));
    flash_redirect('caja.php', $m, $ok ? 'success' : 'danger');
}

if (isset($_POST['cerrar_caja'])) {
    csrf_verify();
    if (trim((string)($_POST['efectivo_contado'] ?? '')) === '') flash_redirect('caja.php', "Indica cuánto efectivo contaste en caja.", 'danger');
    $contado = max(0, (float)$_POST['efectivo_contado']);
    $obs     = trim($_POST['obs_cierre'] ?? '');
    // Se bloquea la caja: las ventas/abonos en curso terminan antes y el esperado incluye todo
    $pdo->beginTransaction();
    $caja = caja_abierta_bloqueada($pdo);
    if (!$caja || (int)$_POST['id_caja'] !== (int)$caja['id']) { $pdo->rollBack(); flash_redirect('caja.php', "La caja ya no está abierta.", 'danger'); }
    $esperado = caja_resumen($pdo, $caja)['efectivo_esperado'];
    $pdo->prepare("UPDATE cajas SET estado='Cerrada', fecha_cierre=NOW(), id_usuario_cierre=?, efectivo_esperado=?, efectivo_contado=?, obs_cierre=? WHERE id=? AND estado='Abierta'")
        ->execute([$_SESSION['id_usuario'], $esperado, $contado, $obs !== '' ? mb_substr($obs, 0, 500) : null, $caja['id']]);
    $pdo->commit();
    header("Location: caja_reporte.php?id={$caja['id']}&cerrada=1"); exit;
}

if (isset($_POST['movimiento'])) {
    csrf_verify();
    if (!$es_admin) flash_redirect('caja.php', "Solo el administrador puede registrar ingresos y egresos manuales.", 'danger');
    $tipo   = ($_POST['tipo'] ?? '') === 'Egreso' ? 'Egreso' : 'Ingreso';
    $monto  = round((float)($_POST['monto'] ?? 0), 2);
    $desc   = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 500);
    $metodo = in_array($_POST['metodo'] ?? '', METODOS_CAJA, true) ? $_POST['metodo'] : 'Efectivo';
    if ($monto <= 0 || $desc === '') flash_redirect('caja.php', "Monto y descripción son obligatorios.", 'danger');
    $pdo->beginTransaction();
    $caja = caja_abierta_bloqueada($pdo);
    if (!$caja) { $pdo->rollBack(); flash_redirect('caja.php', "Abre la caja antes de registrar movimientos.", 'danger'); }
    $pdo->prepare("INSERT INTO caja_chica (tipo,monto,metodo,descripcion,referencia_origen,id_usuario,id_caja) VALUES (?,?,?,?,'Manual',?,?)")
        ->execute([$tipo, $monto, $metodo, $desc, $_SESSION['id_usuario'], $caja['id']]);
    $pdo->commit();
    flash_redirect('caja.php', "{$tipo} de $" . number_format($monto, 2) . " ({$metodo}) registrado.");
}
// Cuadrar un descuadre: faltante → ingreso (se repone el dinero), sobrante → egreso (se retira).
// El movimiento entra a la caja abierta y queda ligado a la caja descuadrada (ajusta_caja).
if (isset($_POST['cuadrar_descuadre'])) {
    csrf_verify();
    if (!$es_admin) flash_redirect('caja.php', "Solo el administrador puede cuadrar descuadres.", 'danger');
    $id_desc = (int)($_POST['id_descuadre'] ?? 0);
    $monto   = round((float)($_POST['monto'] ?? 0), 2);
    // Se bloquean la caja abierta y la caja descuadrada: dos ajustes simultáneos no pasan del pendiente
    $pdo->beginTransaction();
    $caja = caja_abierta_bloqueada($pdo);
    if (!$caja) { $pdo->rollBack(); flash_redirect('caja.php', "Abre la caja para registrar el ajuste: el dinero entra o sale de la caja abierta.", 'danger'); }
    $pdo->prepare("SELECT id FROM cajas WHERE id=? FOR UPDATE")->execute([$id_desc]);
    $d = caja_descuadres($pdo, $id_desc)[0] ?? null;
    if (!$d || $d['estado'] !== 'Pendiente') { $pdo->rollBack(); flash_redirect('caja.php', "Ese descuadre no existe o ya está cuadrado.", 'danger'); }
    if ($monto <= 0 || $monto > $d['pendiente'] + 0.001) {
        $pdo->rollBack();
        flash_redirect('caja.php', "El monto debe ser mayor a cero y no superar lo pendiente ($" . number_format($d['pendiente'], 2) . ").", 'danger');
    }
    $metodo = in_array($_POST['metodo'] ?? '', METODOS_CAJA, true) ? $_POST['metodo'] : 'Efectivo';
    $tipo   = $d['tipo'] === 'Faltante' ? 'Ingreso' : 'Egreso';
    $obs    = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 300);
    $desc   = ($tipo === 'Ingreso' ? 'Reposición de faltante' : 'Retiro de sobrante') . " — caja #{$id_desc} del "
            . date('d/m/Y', strtotime($d['fecha_cierre'])) . ($obs !== '' ? " · {$obs}" : '');
    $pdo->prepare("INSERT INTO caja_chica (tipo,monto,metodo,descripcion,referencia_origen,id_usuario,id_caja,ajusta_caja) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tipo, $monto, $metodo, $desc, "Ajuste caja #{$id_desc}", $_SESSION['id_usuario'], $caja['id'], $id_desc]);
    $pdo->commit();
    $resto = round($d['pendiente'] - $monto, 2);
    flash_redirect('caja.php', $resto < 0.01
        ? "Descuadre de la caja #{$id_desc} cuadrado."
        : "Ajuste registrado. Aún queda pendiente $" . number_format($resto, 2) . " de la caja #{$id_desc}.");
}
[$msg, $msg_type] = flash_get();

$caja = caja_abierta($pdo);
$res  = $caja ? caja_resumen($pdo, $caja) : null;
$cierres = $pdo->query("SELECT c.*, ua.nombre AS usuario_apertura, uc.nombre AS usuario_cierre FROM cajas c
    LEFT JOIN usuarios ua ON c.id_usuario_apertura=ua.id LEFT JOIN usuarios uc ON c.id_usuario_cierre=uc.id
    WHERE c.estado='Cerrada' ORDER BY c.id DESC LIMIT 30")->fetchAll();
$descuadres = array_column(caja_descuadres($pdo), null, 'id');
$desc_pend  = array_values(array_filter($descuadres, fn($d) => $d['estado'] === 'Pendiente'));

// Historial de movimientos con filtros (solo administrador)
$movimientos = []; $ing_fil = $egr_fil = 0;
if ($es_admin) {
$f_tipo   = $_GET['tipo']  ?? '';
$f_desde  = $_GET['desde'] ?? '';
$f_hasta  = $_GET['hasta'] ?? date('Y-m-d');
$f_ref    = trim($_GET['ref'] ?? '');

$where = ['1=1']; $params = [];
if ($f_tipo)  { $where[] = 'c.tipo=?';          $params[] = $f_tipo; }
if ($f_desde) { $where[] = 'DATE(c.fecha)>=?';   $params[] = $f_desde; }
if ($f_hasta) { $where[] = 'DATE(c.fecha)<=?';   $params[] = $f_hasta; }
if ($f_ref)   { $where[] = '(c.descripcion LIKE ? OR c.referencia_origen LIKE ?)'; $params[] = "%$f_ref%"; $params[] = "%$f_ref%"; }

$sql = "SELECT c.*, u.nombre as usuario
    FROM caja_chica c LEFT JOIN usuarios u ON c.id_usuario=u.id
    WHERE " . implode(' AND ', $where) . " ORDER BY c.fecha DESC LIMIT 500";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$movimientos = $stmt->fetchAll();
$ing_fil  = array_sum(array_map(fn($m) => $m['tipo']==='Ingreso' ? (float)$m['monto'] : 0, $movimientos));
$egr_fil  = array_sum(array_map(fn($m) => $m['tipo']==='Egreso'  ? (float)$m['monto'] : 0, $movimientos));
}

function dinero(float $n): string { return '$' . number_format($n, 2); }
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Control de Caja</h1><p>Apertura, movimientos, arqueo y cierre</p></div>
    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#ayudaCaja"><i class="fa-solid fa-circle-question me-1"></i>¿Cómo funciona?</button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="collapse mb-3" id="ayudaCaja">
    <div class="card p-4" style="font-size:.86rem">
        <h6 style="font-weight:700">Cómo funciona la caja</h6>
        <ol class="mb-0 ps-3">
            <li class="mb-1"><b>Apertura:</b> al empezar la jornada se abre la caja con la <b>base en efectivo</b> (el sencillo que hay en el cajón). Sin caja abierta no se pueden registrar ventas ni abonos.</li>
            <li class="mb-1"><b>Durante la jornada:</b> cada venta y abono queda ligado a la caja abierta. Efectivo, transferencia y tarjeta suman dinero; <b>Terceros</b> (Addi, Sistecrédito…) y <b>Crédito Interno</b> no son dinero recibido: quedan en Cartera. Los gastos (pasajes, repuestos) se registran como <b>egreso</b> y los ingresos extra como <b>ingreso manual</b>, indicando si fue en efectivo o por cuenta.</li>
            <li class="mb-1"><b>La caja nunca se cierra sola:</b> sigue abierta aunque cambie el día o se cierre la sesión, hasta que alguien la cierre (vendedor o administrador).</li>
            <li class="mb-1"><b>Cierre (arqueo):</b> cuentas el efectivo del cajón y lo escribes. El sistema calcula el <b>efectivo esperado</b> = base + ventas en efectivo + abonos en efectivo + ingresos en efectivo − egresos en efectivo, y muestra si hay <b>sobrante o faltante</b>.</li>
            <li class="mb-1"><b>Descuadres:</b> si al cerrar falta o sobra efectivo, el descuadre queda registrado con la fecha y quién cerró, y aparece como <b>pendiente</b> aquí, en el reporte de cada cierre y en Reportes. No se borra: se cuadra cuando el administrador registra la <b>reposición</b> del faltante (entrada) o el <b>retiro</b> del sobrante (salida) con el botón «Cuadrar». Se puede cuadrar por partes.</li>
            <li><b>Reporte:</b> al cerrar se genera el reporte detallado (descargable en PDF): cuadre de efectivo, dinero en cuentas (transferencias y tarjeta), cartera generada, cada venta con cliente y productos, productos vendidos, abonos y movimientos manuales.</li>
        </ol>
    </div>
</div>

<?php if ($desc_pend):
    $tot_falt = array_sum(array_map(fn($d) => $d['tipo'] === 'Faltante' ? $d['pendiente'] : 0, $desc_pend));
    $tot_sobr = array_sum(array_map(fn($d) => $d['tipo'] === 'Sobrante' ? $d['pendiente'] : 0, $desc_pend)); ?>
<!-- Descuadres pendientes -->
<div class="card mb-3" style="border-left:4px solid #dc2626 !important">
    <div class="card-header-custom d-flex flex-wrap align-items-center gap-2">
        <h6 class="mb-0" style="font-weight:700"><i class="fa-solid fa-triangle-exclamation me-2 text-danger"></i>Descuadres pendientes (<?= count($desc_pend) ?>)</h6>
        <span class="ms-auto" style="font-size:.82rem">
            <?php if ($tot_falt > 0): ?>Faltante por reponer: <b class="text-danger"><?= dinero($tot_falt) ?></b><?php endif; ?>
            <?php if ($tot_sobr > 0): ?>&nbsp; Sobrante por retirar: <b class="text-primary"><?= dinero($tot_sobr) ?></b><?php endif; ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Caja</th><th>Fecha del cierre</th><th>Cerró</th><th>Tipo</th><th class="text-end">Diferencia</th><th class="text-end">Ajustado</th><th class="text-end">Pendiente</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($desc_pend as $d): ?>
                <tr>
                    <td style="font-weight:600">#<?= $d['id'] ?></td>
                    <td style="font-size:.82rem"><?= date('d/m/Y h:i A', strtotime($d['fecha_cierre'])) ?></td>
                    <td style="font-size:.82rem"><?= h($d['usuario_cierre'] ?? '—') ?></td>
                    <td><span class="badge <?= $d['tipo'] === 'Faltante' ? 'badge-danger' : 'badge-info' ?>"><?= $d['tipo'] ?></span></td>
                    <td class="text-end"><?= dinero($d['monto']) ?></td>
                    <td class="text-end"><?= dinero($d['ajustado']) ?></td>
                    <td class="text-end" style="font-weight:700;color:<?= $d['tipo'] === 'Faltante' ? '#dc2626' : '#2563eb' ?>"><?= dinero($d['pendiente']) ?></td>
                    <td><div class="d-flex gap-1">
                        <a href="caja_reporte.php?id=<?= $d['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="Ver reporte"><i class="fa-solid fa-file-lines"></i></a>
                        <?php if ($es_admin): ?>
                        <button type="button" class="btn btn-xs <?= $caja ? 'btn-success' : 'btn-outline-secondary' ?>" <?= $caja ? '' : 'disabled title="Abre la caja para registrar el ajuste"' ?>
                            data-bs-toggle="modal" data-bs-target="#modalCuadrar"
                            data-id="<?= $d['id'] ?>" data-tipo="<?= $d['tipo'] ?>" data-pendiente="<?= $d['pendiente'] ?>"
                            data-fecha="<?= date('d/m/Y', strtotime($d['fecha_cierre'])) ?>"><i class="fa-solid fa-scale-balanced me-1"></i>Cuadrar</button>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!$es_admin): ?><div class="px-3 py-2" style="font-size:.78rem;color:#64748b">El administrador cuadra estos descuadres registrando la reposición o el retiro del dinero.</div><?php endif; ?>
    <?php if ($es_admin && !$caja): ?><div class="px-3 py-2" style="font-size:.78rem;color:#64748b">Abre la caja para poder cuadrar: el ajuste entra o sale de la caja abierta.</div><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$caja): ?>
<!-- Caja cerrada -->
<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card p-4 h-100">
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge badge-danger" style="font-size:.8rem"><i class="fa-solid fa-lock me-1"></i>Caja cerrada</span>
            </div>
            <h6 style="font-weight:700"><i class="fa-solid fa-lock-open me-2 text-success"></i>Abrir caja</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-2"><label class="form-label">Base inicial en efectivo ($)</label><input type="number" name="monto_inicial" class="form-control" step="0.01" min="0" value="0" required></div>
                <div class="mb-3"><label class="form-label">Observación</label><input type="text" name="obs_apertura" class="form-control" placeholder="Apertura de jornada"></div>
                <button type="submit" name="abrir_caja" class="btn btn-success w-100">Abrir Caja</button>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card p-4 h-100 d-flex justify-content-center" style="color:#64748b;font-size:.88rem">
            <p class="mb-2"><i class="fa-solid fa-circle-info me-1"></i>No hay una caja abierta. Mientras esté cerrada <b>no se pueden registrar ventas, abonos ni movimientos</b>.</p>
            <?php if ($cierres): $u = $cierres[0]; ?>
            <p class="mb-0">Último cierre: caja #<?= $u['id'] ?> el <?= date('d/m/Y h:i A', strtotime($u['fecha_cierre'])) ?> por <?= h($u['usuario_cierre'] ?? '—') ?>.
                <a href="caja_reporte.php?id=<?= $u['id'] ?>" target="_blank">Ver reporte</a></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else:
    $ef = $res['medios']['Efectivo']; ?>
<!-- Caja abierta -->
<div class="card p-3 mb-3 d-flex flex-row flex-wrap align-items-center gap-3" style="border-left:4px solid #16a34a !important">
    <span class="badge badge-success" style="font-size:.8rem"><i class="fa-solid fa-lock-open me-1"></i>Caja #<?= $caja['id'] ?> abierta</span>
    <span style="font-size:.85rem;color:#475569">Desde <b><?= date('d/m/Y h:i A', strtotime($caja['fecha_apertura'])) ?></b> · por <?= h($caja['usuario_apertura'] ?? '—') ?> · base <b><?= dinero((float)$caja['monto_inicial']) ?></b></span>
    <a href="caja_reporte.php?id=<?= $caja['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto"><i class="fa-solid fa-file-lines me-1"></i>Ver reporte parcial</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1">Ventas (<?= $res['num_ventas'] ?>)</div>
        <div class="stat-value text-primary" style="font-size:1.3rem"><?= dinero($res['total_ventas']) ?></div>
        <div style="font-size:.72rem;color:#64748b"><?= $res['unidades'] ?> und. vendidas</div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1">Efectivo esperado</div>
        <div class="stat-value text-success" style="font-size:1.3rem"><?= dinero($res['efectivo_esperado']) ?></div>
        <div style="font-size:.72rem;color:#64748b">Debe haber en el cajón</div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1">En cuentas</div>
        <div class="stat-value" style="font-size:1.3rem;color:#7c3aed"><?= dinero($res['cuentas']) ?></div>
        <div style="font-size:.72rem;color:#64748b">Transferencias + tarjeta</div>
    </div></div>
    <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="stat-label mb-1">A cartera</div>
        <div class="stat-value text-warning" style="font-size:1.3rem"><?= dinero($res['cartera']) ?></div>
        <div style="font-size:.72rem;color:#64748b">Terceros + crédito interno</div>
    </div></div>
</div>

<div class="row g-3 mb-4">
    <?php if ($es_admin): ?>
    <div class="col-lg-4">
        <!-- Egreso -->
        <div class="card p-4 mb-3">
            <h6 class="mb-3" style="font-weight:700"><i class="fa-solid fa-arrow-up-right-from-square me-2 text-danger"></i>Egreso / Gasto</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="tipo" value="Egreso">
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Monto ($)</label><input type="number" name="monto" class="form-control" step="0.01" min="0.01" placeholder="0.00" required></div>
                    <div class="col-6"><label class="form-label">Sale de</label><select name="metodo" class="form-select"><option value="Efectivo">Efectivo</option><option value="Transferencia">Cuenta (transf.)</option><option value="Tarjeta">Tarjeta</option></select></div>
                </div>
                <div class="mb-3"><label class="form-label">Descripción detallada</label><textarea name="descripcion" class="form-control" rows="2" placeholder="Ej. Compra de repuestos, pasajes..." required></textarea></div>
                <button type="submit" name="movimiento" class="btn btn-danger w-100">Registrar Egreso</button>
            </form>
        </div>
        <!-- Ingreso -->
        <div class="card p-4">
            <h6 class="mb-3" style="font-weight:700"><i class="fa-solid fa-arrow-down me-2 text-primary"></i>Ingreso Manual</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="tipo" value="Ingreso">
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Monto ($)</label><input type="number" name="monto" class="form-control" step="0.01" min="0.01" placeholder="0.00" required></div>
                    <div class="col-6"><label class="form-label">Entra a</label><select name="metodo" class="form-select"><option value="Efectivo">Efectivo</option><option value="Transferencia">Cuenta (transf.)</option><option value="Tarjeta">Tarjeta</option></select></div>
                </div>
                <div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2" placeholder="Ej. Consignación, adelanto..." required></textarea></div>
                <button type="submit" name="movimiento" class="btn btn-primary w-100">Registrar Ingreso</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $es_admin ? 'col-lg-8' : 'col-12' ?>">
        <?php if (!$es_admin): ?>
        <div class="alert alert-info py-2 mb-3" style="font-size:.82rem"><i class="fa-solid fa-circle-info me-1"></i>Al final del día cuenta el efectivo del cajón, revisa que las transferencias y pagos con tarjeta coincidan con la tabla y cierra la caja. Si hubo un gasto en efectivo, pide al administrador que lo registre antes de cerrar.</div>
        <?php endif; ?>
        <!-- Dinero por medio -->
        <div class="card mb-3">
            <div class="card-header-custom"><h6 class="mb-0" style="font-weight:700">Dinero de esta caja por medio de pago</h6></div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Medio</th><th class="text-end">Ventas</th><th class="text-end">Abonos</th><th class="text-end">Ingresos</th><th class="text-end">Egresos</th><th class="text-end">Neto</th></tr></thead>
                    <tbody>
                    <?php foreach ($res['medios'] as $m => $t): ?>
                        <tr>
                            <td style="font-weight:600"><?= h($m) ?></td>
                            <td class="text-end"><?= dinero($t['ventas']) ?></td>
                            <td class="text-end"><?= dinero($t['abonos']) ?></td>
                            <td class="text-end"><?= dinero($t['ingresos']) ?></td>
                            <td class="text-end text-danger"><?= $t['egresos'] > 0 ? '−' . dinero($t['egresos']) : dinero(0) ?></td>
                            <td class="text-end" style="font-weight:700"><?= dinero($t['neto']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Cierre -->
        <div class="card p-4" style="border-top:3px solid #dc2626 !important">
            <h6 class="mb-3" style="font-weight:700"><i class="fa-solid fa-lock me-2 text-danger"></i>Cerrar caja (arqueo)</h6>
            <div class="row g-3">
                <div class="col-md-6" style="font-size:.85rem">
                    <table class="table table-sm mb-0">
                        <tr><td>Base inicial</td><td class="text-end"><?= dinero($res['base']) ?></td></tr>
                        <tr><td>+ Ventas en efectivo</td><td class="text-end"><?= dinero($ef['ventas']) ?></td></tr>
                        <tr><td>+ Abonos en efectivo</td><td class="text-end"><?= dinero($ef['abonos']) ?></td></tr>
                        <tr><td>+ Ingresos en efectivo</td><td class="text-end"><?= dinero($ef['ingresos']) ?></td></tr>
                        <tr><td>− Egresos en efectivo</td><td class="text-end text-danger"><?= dinero($ef['egresos']) ?></td></tr>
                        <tr><td style="font-weight:700">= Efectivo esperado</td><td class="text-end" style="font-weight:700" id="esperado" data-valor="<?= $res['efectivo_esperado'] ?>"><?= dinero($res['efectivo_esperado']) ?></td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <form method="POST" id="formCierre" data-confirm="¿Cerrar la caja #<?= $caja['id'] ?>? Después del cierre no se podrán registrar más movimientos en ella." data-confirm-btn="Sí, cerrar caja">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id_caja" value="<?= $caja['id'] ?>">
                        <input type="hidden" name="cerrar_caja" value="1">
                        <div class="mb-2"><label class="form-label">Efectivo contado en el cajón ($) *</label><input type="number" name="efectivo_contado" id="contado" class="form-control" step="0.01" min="0" required></div>
                        <div class="mb-2" id="difCierre" style="font-size:.85rem;font-weight:600"></div>
                        <div class="mb-3"><label class="form-label">Observación de cierre</label><input type="text" name="obs_cierre" class="form-control" placeholder="Ej. Se consignó a banco, faltante por…"></div>
                        <button type="submit" class="btn btn-danger w-100"><i class="fa-solid fa-lock me-1"></i>Cerrar caja y generar reporte</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Historial de cierres -->
<div class="card mb-4">
    <div class="card-header-custom"><h6 class="mb-0" style="font-weight:700">Cierres de caja</h6></div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Caja</th><th>Apertura</th><th>Cierre</th><th class="text-end">Base</th><th class="text-end">Esperado</th><th class="text-end">Contado</th><th class="text-end">Diferencia</th><th>Reporte</th></tr></thead>
            <tbody>
            <?php if (empty($cierres)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-vault"></i>Aún no hay cierres registrados</div></td></tr>
            <?php else: foreach ($cierres as $c): $dif = (float)$c['efectivo_contado'] - (float)$c['efectivo_esperado']; $dq = $descuadres[$c['id']] ?? null; ?>
                <tr>
                    <td style="font-weight:600">#<?= $c['id'] ?></td>
                    <td style="font-size:.8rem"><?= date('d/m/Y h:i A', strtotime($c['fecha_apertura'])) ?><br><span class="text-muted"><?= h($c['usuario_apertura'] ?? '—') ?></span></td>
                    <td style="font-size:.8rem"><?= date('d/m/Y h:i A', strtotime($c['fecha_cierre'])) ?><br><span class="text-muted"><?= h($c['usuario_cierre'] ?? '—') ?></span></td>
                    <td class="text-end"><?= dinero((float)$c['monto_inicial']) ?></td>
                    <td class="text-end"><?= dinero((float)$c['efectivo_esperado']) ?></td>
                    <td class="text-end"><?= dinero((float)$c['efectivo_contado']) ?></td>
                    <td class="text-end"><span class="badge <?= abs($dif) < 0.01 ? 'badge-success' : ($dif > 0 ? 'badge-info' : 'badge-danger') ?>"><?= abs($dif) < 0.01 ? 'Cuadrada' : ($dif > 0 ? 'Sobra ' : 'Falta ') . dinero(abs($dif)) ?></span>
                        <?php if ($dq): ?><div style="font-size:.72rem;margin-top:2px" class="<?= $dq['estado'] === 'Pendiente' ? 'text-danger' : 'text-success' ?>"><?= $dq['estado'] === 'Pendiente' ? 'Pendiente ' . dinero($dq['pendiente']) : '✔ Cuadrado ' . date('d/m/Y', strtotime($dq['ultimo_ajuste'])) ?></div><?php endif; ?></td>
                    <td><a href="caja_reporte.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-xs btn-outline-primary"><i class="fa-solid fa-file-pdf me-1"></i>Ver / PDF</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($es_admin): ?>
<!-- Historial de movimientos -->
<div class="card">
    <div class="card-header-custom">
        <form method="GET" class="row g-2">
            <div class="col-sm-3">
                <select name="tipo" class="form-select form-select-sm">
                    <option value="">Todos los tipos</option>
                    <option value="Ingreso" <?= $f_tipo==='Ingreso'?'selected':'' ?>>Ingreso</option>
                    <option value="Egreso"  <?= $f_tipo==='Egreso' ?'selected':'' ?>>Egreso</option>
                </select>
            </div>
            <div class="col-sm-3"><input type="date" name="desde" class="form-control form-control-sm" value="<?= h($f_desde) ?>"></div>
            <div class="col-sm-3"><input type="date" name="hasta" class="form-control form-control-sm" value="<?= h($f_hasta) ?>"></div>
            <div class="col-sm-3"><input type="text" name="ref" class="form-control form-control-sm" placeholder="Referencia/Ticket" value="<?= h($f_ref) ?>"></div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-primary">Filtrar</button>
                <a href="caja.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                <span class="ms-auto text-muted small d-flex flex-wrap align-items-center column-gap-1">
                    Ingresos: <strong class="text-success ms-1"><?= dinero($ing_fil) ?></strong>
                    &nbsp;Egresos: <strong class="text-danger ms-1"><?= dinero($egr_fil) ?></strong>
                    &nbsp;Neto: <strong class="<?= ($ing_fil-$egr_fil)>=0?'text-primary':'text-danger' ?> ms-1"><?= dinero($ing_fil-$egr_fil) ?></strong>
                </span>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>#</th><th>Fecha</th><th>Caja</th><th>Tipo</th><th>Monto</th><th>Medio</th><th>Descripción</th><th>Ref.</th><th>Usuario</th></tr>
            </thead>
            <tbody>
                <?php if(empty($movimientos)): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fa-solid fa-receipt"></i>No hay movimientos</div></td></tr>
                <?php else: foreach($movimientos as $m): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:.78rem"><?= $m['id'] ?></td>
                    <td style="font-size:.8rem"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                    <td style="font-size:.78rem;color:#64748b"><?= $m['id_caja'] ? '#' . $m['id_caja'] : '—' ?></td>
                    <td><span class="badge <?= $m['tipo']==='Ingreso'?'badge-success':'badge-danger' ?>"><?= $m['tipo'] ?></span></td>
                    <td style="font-weight:600;color:<?= $m['tipo']==='Ingreso'?'#16a34a':'#dc2626' ?>"><?= dinero((float)$m['monto']) ?></td>
                    <td style="font-size:.78rem"><?= h($m['metodo'] ?? '—') ?></td>
                    <td style="font-size:.82rem"><?= h(mb_strimwidth($m['descripcion'] ?? '', 0, 55, '…')) ?></td>
                    <td style="font-size:.78rem;color:#64748b"><?= h($m['referencia_origen'] ?? '—') ?></td>
                    <td style="font-size:.78rem"><?= h($m['usuario'] ?? '—') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($es_admin && $desc_pend && $caja): ?>
<!-- Modal: cuadrar descuadre -->
<div class="modal fade" id="modalCuadrar" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content" style="border-radius:16px">
        <div class="modal-header border-0 pb-0"><h5 class="modal-title" style="font-weight:700" id="cq_titulo">Cuadrar descuadre</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id_descuadre" id="cq_id">
            <div class="modal-body pt-3">
                <p class="small text-muted" id="cq_info"></p>
                <div class="row g-2">
                    <div class="col-6 mb-3"><label class="form-label">Monto ($) *</label><input type="number" name="monto" id="cq_monto" class="form-control" step="0.01" min="0.01" required></div>
                    <div class="col-6 mb-3"><label class="form-label" id="cq_lbl_medio">Medio</label><select name="metodo" class="form-select"><option value="Efectivo">Efectivo</option><option value="Transferencia">Cuenta (transf.)</option><option value="Tarjeta">Tarjeta</option></select></div>
                </div>
                <div class="mb-1"><label class="form-label">Detalle</label><input type="text" name="descripcion" class="form-control" placeholder="Ej. Lo repuso el vendedor, se encontró en…"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" name="cuadrar_descuadre" class="btn btn-success">Registrar ajuste</button>
            </div>
        </form>
    </div></div>
</div>
<script>
document.getElementById('modalCuadrar').addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget, falt = b.dataset.tipo === 'Faltante';
    const pend = parseFloat(b.dataset.pendiente).toLocaleString('en-US', {minimumFractionDigits: 2});
    document.getElementById('cq_id').value = b.dataset.id;
    document.getElementById('cq_titulo').textContent = (falt ? 'Reponer faltante' : 'Retirar sobrante') + ' · caja #' + b.dataset.id;
    document.getElementById('cq_info').textContent = (falt
        ? 'Registra el dinero que se repone (entrada a la caja abierta). '
        : 'Registra el sobrante que se retira (salida de la caja abierta). ')
        + 'Cierre del ' + b.dataset.fecha + ' · pendiente $' + pend + '. Puedes cuadrarlo por partes.';
    document.getElementById('cq_lbl_medio').textContent = falt ? 'Entra a' : 'Sale de';
    const m = document.getElementById('cq_monto');
    m.setAttribute('max', b.dataset.pendiente);
    m.value = b.dataset.pendiente;
});
</script>
<?php endif; ?>

<?php if ($caja): ?>
<script>
// Muestra sobrante/faltante mientras se escribe el efectivo contado
(function () {
    const esperado = parseFloat(document.getElementById('esperado').dataset.valor);
    const out = document.getElementById('difCierre');
    const fmt = n => '$' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    function pintar() {
        const v = document.getElementById('contado').value;
        if (v === '') { out.textContent = ''; return; }
        const d = Math.round((parseFloat(v) - esperado) * 100) / 100;
        out.textContent = d === 0 ? '✔ Caja cuadrada' : (d > 0 ? 'Sobrante: ' : 'Faltante: ') + fmt(Math.abs(d));
        out.style.color = d === 0 ? '#16a34a' : (d > 0 ? '#2563eb' : '#dc2626');
    }
    document.getElementById('formCierre').addEventListener('input', pintar);
    document.getElementById('formCierre').addEventListener('change', pintar);
})();
</script>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
