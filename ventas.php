<?php
require 'config/conexion.php';
require 'includes/caja_funciones.php';

// Remove item from cart (clave "p{id}" = vendido, "g{id}" = obsequio)
if (isset($_GET['eliminar'])) {
    unset($_SESSION['carrito'][(string)$_GET['eliminar']]);
    header("Location: ventas.php"); exit;
}

// Flash messages
$flash_ok  = $_SESSION['flash_ok']  ?? null;
$flash_err = $_SESSION['flash_err'] ?? null;
$ticket_id = $_SESSION['ticket_id'] ?? null;
unset($_SESSION['flash_ok'], $_SESSION['flash_err'], $_SESSION['ticket_id']);

if (!isset($_SESSION['carrito'])) $_SESSION['carrito'] = [];
// Carritos guardados con el formato anterior (sin 'id') se descartan
foreach ($_SESSION['carrito'] as $it) { if (!isset($it['id'])) { $_SESSION['carrito'] = []; break; } }

// Unidades de un producto ya en el carrito (vendidas + obsequiadas)
function en_carrito(int $id): int {
    $n = 0;
    foreach ($_SESSION['carrito'] as $it) if ($it['id'] === $id) $n += $it['cantidad'];
    return $n;
}

// Add to cart
if (isset($_POST['agregar'])) {
    csrf_verify();
    $id   = (int)$_POST['id_producto'];
    $cant = max(1, (int)$_POST['cantidad']);
    $obsequio = !empty($_POST['obsequio']);
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id=? AND stock>0");
    $stmt->execute([$id]);
    $prod = $stmt->fetch();
    if (!$prod) {
        $_SESSION['flash_err'] = "Producto no encontrado o sin stock.";
    } else {
        $label = producto_label($prod['nombre'], $prod['color']);
        $disp  = (int)$prod['stock'] - en_carrito($id);
        if ($cant > $disp) {
            $_SESSION['flash_err'] = "Solo {$disp} disponible(s) de «{$label}».";
        } else {
            $key = ($obsequio ? 'g' : 'p') . $id;
            if (isset($_SESSION['carrito'][$key])) {
                $_SESSION['carrito'][$key]['cantidad'] += $cant;
            } else {
                $_SESSION['carrito'][$key] = [
                    'id' => $id, 'nombre' => $label, 'cantidad' => $cant, 'obsequio' => $obsequio,
                    'pvp' => (float)$prod['precio_venta'], 'precio' => $obsequio ? 0.0 : (float)$prod['precio_venta'],
                ];
            }
            $_SESSION['flash_ok'] = $obsequio ? "Obsequio agregado al carrito." : "Producto agregado.";
        }
    }
    header("Location: ventas.php"); exit;
}

// Quick client
if (isset($_POST['guardar_cliente'])) {
    csrf_verify();
    $nombre = trim($_POST['nombre_cliente']);
    $doc    = trim($_POST['doc_cliente']);
    if ($nombre !== '' && $doc !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO clientes (nombre,documento,telefono,email) VALUES (?,?,?,?)");
            $stmt->execute([$nombre, $doc, trim($_POST['tel_cliente']), trim($_POST['email_cliente'])]);
            $_SESSION['flash_ok']      = "Cliente guardado.";
            $_SESSION['sel_cliente']   = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            $_SESSION['flash_err'] = "El documento ya está registrado.";
        }
    } else {
        $_SESSION['flash_err'] = "Nombre y documento son obligatorios.";
    }
    header("Location: ventas.php"); exit;
}

// Abrir caja desde el punto de venta (sin caja abierta no se puede vender)
if (isset($_POST['abrir_caja'])) {
    csrf_verify();
    [$ok, $m] = caja_abrir($pdo, (float)$_POST['monto_inicial'], trim($_POST['obs_apertura'] ?? ''));
    $_SESSION[$ok ? 'flash_ok' : 'flash_err'] = $m;
    header("Location: ventas.php"); exit;
}

// Process sale
if (isset($_POST['procesar_venta']) && !empty($_SESSION['carrito'])) {
    csrf_verify();
    try {
        $caja = caja_abierta($pdo);
        if (!$caja) throw new RuntimeException("La caja está cerrada. Ábrela antes de registrar ventas.");

        // Validate stock (sumando unidades vendidas y obsequiadas del mismo producto)
        $row = $pdo->prepare("SELECT stock FROM productos WHERE id=?");
        foreach ($_SESSION['carrito'] as $item) {
            $row->execute([$item['id']]);
            $cur = $row->fetchColumn();
            if ($cur === false || en_carrito($item['id']) > (int)$cur) {
                throw new RuntimeException("Stock insuficiente para «{$item['nombre']}». Disponible: " . ((int)$cur));
            }
        }

        $subtotal  = array_sum(array_map(fn($i) => $i['precio'] * $i['cantidad'], $_SESSION['carrito']));
        $descuento = max(0, min((float)($_POST['descuento'] ?? 0), $subtotal));
        $total     = $subtotal - $descuento;
        $estado    = (string)($_POST['estado'] ?? '');
        $obs       = trim($_POST['observacion'] ?? '');
        $id_cliente = (int)($_POST['id_cliente'] ?? 1);
        if (!isset(FORMAS_PAGO[$estado])) throw new RuntimeException("Forma de pago no válida.");
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE id=?");
        $st->execute([$id_cliente]);
        if (!$st->fetchColumn()) throw new RuntimeException("El cliente seleccionado no existe.");

        $pagos_validos = [];
        if ($estado === 'Mixto') {
            $total_pagado = 0;
            foreach ($_POST['pagos'] ?? [] as $p) {
                $met  = trim((string)($p['metodo'] ?? ''));
                $mnt  = (float)($p['monto'] ?? 0);
                if ($met === '' || $mnt <= 0) continue;
                if ($met === 'Mixto' || !isset(FORMAS_PAGO[$met])) throw new RuntimeException("Método de pago no válido en el pago mixto.");
                $pagos_validos[] = ['metodo' => $met, 'monto' => $mnt]; $total_pagado += $mnt;
            }
            if (empty($pagos_validos) || abs($total_pagado - $total) > 0.02) {
                throw new RuntimeException("La suma de los pagos ($" . number_format($total_pagado,2) . ") debe coincidir con el total ($" . number_format($total,2) . ").");
            }
        } else {
            $pagos_validos = [['metodo' => $estado, 'monto' => $total]];
        }

        $metodos = array_column($pagos_validos, 'metodo');
        $entidad = $referencia = $id_autoriza = null;

        // Terceros: entidad obligatoria (Addi, Sistecrédito u otra)
        if (in_array('Terceros', $metodos, true)) {
            $entidad = trim((string)($_POST['entidad'] ?? ''));
            if ($entidad === 'Otra') $entidad = trim((string)($_POST['entidad_otra'] ?? ''));
            if ($entidad === '') throw new RuntimeException("Indica la entidad de Terceros (Addi, Sistecrédito u otra).");
            $entidad    = mb_substr($entidad, 0, 100);
            $referencia = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 100) ?: null;
        }
        // Crédito Interno: a nombre de un cliente registrado y con quién lo autoriza
        if (in_array('Credito Interno', $metodos, true)) {
            if ($id_cliente === 1) throw new RuntimeException("El Crédito Interno debe quedar a nombre de un cliente registrado, no de Cliente General.");
            $id_autoriza = (int)($_POST['id_autoriza'] ?? 0);
            $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id=?");
            $st->execute([$id_autoriza]);
            if (!$id_autoriza || !$st->fetchColumn()) throw new RuntimeException("Indica quién autoriza el Crédito Interno.");
        }

        $saldo_pendiente = 0;
        foreach ($pagos_validos as $p) if (in_array($p['metodo'], METODOS_CARTERA, true)) $saldo_pendiente += $p['monto'];

        $pdo->beginTransaction();
        $caja = caja_abierta_bloqueada($pdo);
        if (!$caja) throw new RuntimeException("La caja se cerró mientras registrabas la venta. Ábrela e intenta de nuevo.");
        $stmt = $pdo->prepare("INSERT INTO ventas (id_usuario,id_cliente,id_caja,subtotal,descuento,total,estado,entidad_financiera,referencia_credito,id_autoriza,saldo_pendiente,observacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$_SESSION['id_usuario'], $id_cliente, $caja['id'], $subtotal, $descuento, $total, $estado, $entidad, $referencia, $id_autoriza, $saldo_pendiente, $obs]);
        $id_venta = (int)$pdo->lastInsertId();

        // Desglose de pagos (en ventas de un solo método queda una fila)
        $insPago = $pdo->prepare("INSERT INTO pagos_venta (id_venta,metodo,monto,entidad) VALUES (?,?,?,?)");
        foreach ($pagos_validos as $p) $insPago->execute([$id_venta, $p['metodo'], $p['monto'], $p['metodo'] === 'Terceros' ? $entidad : null]);

        $insDet = $pdo->prepare("INSERT INTO detalles_venta (id_venta,id_producto,cantidad,precio_unitario,subtotal,es_obsequio) VALUES (?,?,?,?,?,?)");
        // stock>=? evita vender más de lo disponible si dos ventas se registran al mismo tiempo
        $updStk = $pdo->prepare("UPDATE productos SET stock=stock-? WHERE id=? AND stock>=?");
        $insObs = $pdo->prepare("INSERT INTO inventario_movimientos (id_producto,tipo,cantidad,motivo,id_venta,id_usuario) VALUES (?,'Obsequio',?,?,?,?)");
        foreach ($_SESSION['carrito'] as $item) {
            $sub = $item['precio'] * $item['cantidad'];
            $insDet->execute([$id_venta, $item['id'], $item['cantidad'], $item['precio'], $sub, $item['obsequio'] ? 1 : 0]);
            $updStk->execute([$item['cantidad'], $item['id'], $item['cantidad']]);
            if ($updStk->rowCount() === 0) throw new RuntimeException("Stock insuficiente para «{$item['nombre']}».");
            if ($item['obsequio']) {
                // Queda en el historial de Averiados / Obsequios para su control
                $insObs->execute([$item['id'], $item['cantidad'], "Obsequio en venta #{$id_venta}", $id_venta, $_SESSION['id_usuario']]);
            }
        }

        foreach ($pagos_validos as $p) {
            if ($p['monto'] > 0 && in_array($p['metodo'], METODOS_CAJA, true)) {
                $desc = !empty($obs) ? $obs : "Venta #{$id_venta} — " . forma_pago_label($p['metodo']);
                $pdo->prepare("INSERT INTO caja_chica (tipo,monto,metodo,descripcion,referencia_origen,id_usuario,id_caja) VALUES ('Ingreso',?,?,?,?,?,?)")
                    ->execute([$p['monto'], $p['metodo'], $desc, "Venta #{$id_venta}", $_SESSION['id_usuario'], $caja['id']]);
            }
        }

        $pdo->commit();
        $_SESSION['carrito']  = [];
        $_SESSION['flash_ok'] = "Venta registrada exitosamente.";
        $_SESSION['ticket_id'] = $id_venta;
        header("Location: ventas.php"); exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$e instanceof RuntimeException) error_log('Venta: ' . $e->getMessage());
        $_SESSION['flash_err'] = $e instanceof RuntimeException ? $e->getMessage() : "No se pudo registrar la venta. Intenta de nuevo.";
        header("Location: ventas.php"); exit;
    }
}

$productos = $pdo->query("SELECT id,nombre,color,precio_venta,stock FROM productos WHERE stock>0 ORDER BY nombre, color")->fetchAll();
$clientes  = $pdo->query("SELECT id,nombre,documento FROM clientes ORDER BY nombre")->fetchAll();
$autorizadores = $pdo->query("SELECT id,nombre,rol FROM usuarios ORDER BY rol='admin' DESC, nombre")->fetchAll();
$metodos_mixto = array_diff_key(FORMAS_PAGO, ['Mixto' => 1]);
$carrito   = $_SESSION['carrito'];
$subtotal  = array_sum(array_map(fn($i) => $i['precio'] * $i['cantidad'], $carrito));
$caja = caja_abierta($pdo);
$sel_cliente = $_SESSION['sel_cliente'] ?? 1;   // por defecto: Cliente General (Mostrador)
unset($_SESSION['sel_cliente']);

include 'includes/header.php';
?>
<div class="page-header">
    <div><h1>Punto de Venta</h1><p>Registra ventas y gestiona el carrito de compras</p></div>
</div>

<?php if ($flash_err): ?><div class="alert alert-danger alert-dismiss mb-3"><?= h($flash_err) ?></div><?php endif; ?>
<?php if ($flash_ok):  ?><div class="alert alert-success alert-dismiss mb-3"><?= h($flash_ok) ?>
    <?php if ($ticket_id): ?> &nbsp;<a href="ticket.php?id=<?= $ticket_id ?>" target="_blank" class="btn btn-sm btn-dark ms-2"><i class="fa-solid fa-receipt me-1"></i>Imprimir Ticket</a><?php endif; ?>
</div><?php endif; ?>

<?php if (!$caja): ?>
<div class="card p-3 mb-3" style="border-left:4px solid #dc2626 !important">
    <form method="POST" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-lg-4">
            <div style="font-weight:700"><i class="fa-solid fa-lock me-1 text-danger"></i>La caja está cerrada</div>
            <div style="font-size:.8rem;color:#64748b">Ábrela con la base en efectivo del cajón para poder cobrar.</div>
        </div>
        <div class="col-sm-4 col-lg-3"><label class="form-label">Base inicial ($)</label><input type="number" name="monto_inicial" class="form-control" step="0.01" min="0" value="0" required></div>
        <div class="col-sm-5 col-lg-3"><label class="form-label">Observación</label><input type="text" name="obs_apertura" class="form-control" placeholder="Apertura de jornada"></div>
        <div class="col-sm-3 col-lg-2"><button type="submit" name="abrir_caja" class="btn btn-success w-100"><i class="fa-solid fa-lock-open me-1"></i>Abrir caja</button></div>
    </form>
</div>
<?php else: ?>
<div class="mb-3" style="font-size:.8rem;color:#64748b"><span class="badge badge-success"><i class="fa-solid fa-lock-open me-1"></i>Caja #<?= $caja['id'] ?> abierta</span> desde <?= date('d/m/Y h:i A', strtotime($caja['fecha_apertura'])) ?> por <?= h($caja['usuario_apertura'] ?? '—') ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Left: Add product + Quick client -->
    <div class="col-lg-4">
        <!-- Add to cart -->
        <div class="card p-4 mb-3">
            <h6 class="fw-700 mb-3" style="font-weight:700"><i class="fa-solid fa-plus me-2 text-primary"></i>Agregar Producto</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-2">
                    <label class="form-label">Buscar producto</label>
                    <input type="text" id="buscarProd" class="form-control mb-2" placeholder="Escribir para filtrar...">
                    <select name="id_producto" id="selectProd" class="form-select" required size="5" style="height:auto;">
                        <?php foreach($productos as $p): ?>
                        <option value="<?= $p['id'] ?>" data-precio="<?= $p['precio_venta'] ?>">
                            <?= h(producto_label($p['nombre'], $p['color'])) ?> · $<?= number_format($p['precio_venta'],2) ?> · <?= $p['stock'] ?> disp.
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="cantidad" class="form-control" value="1" min="1" required>
                </div>
                <div class="form-check mb-3 p-2 ps-5 rounded-3" style="background:#fdf2f8">
                    <input class="form-check-input" type="checkbox" name="obsequio" value="1" id="chkObsequio">
                    <label class="form-check-label" for="chkObsequio" style="font-size:.85rem">
                        <i class="fa-solid fa-gift me-1" style="color:#db2777"></i><b>Es obsequio</b>
                        <span class="d-block text-muted" style="font-size:.75rem">Vidrio templado, forro, cargador… sale a $0 y se descuenta del inventario</span>
                    </label>
                </div>
                <button type="submit" name="agregar" class="btn btn-primary w-100">Agregar al carrito</button>
            </form>
        </div>

        <!-- Quick client -->
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700"><i class="fa-solid fa-user-plus me-2 text-secondary"></i>Nuevo cliente rápido</h6>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="mb-2"><input type="text" name="nombre_cliente" class="form-control" placeholder="Nombre completo" required></div>
                <div class="mb-2"><input type="text" name="doc_cliente" class="form-control" placeholder="Documento / NIT" required></div>
                <div class="mb-2"><input type="text" name="tel_cliente" class="form-control" placeholder="Teléfono"></div>
                <div class="mb-3"><input type="email" name="email_cliente" class="form-control" placeholder="Email"></div>
                <button type="submit" name="guardar_cliente" class="btn btn-outline-secondary w-100">Guardar Cliente</button>
            </form>
        </div>
    </div>

    <!-- Right: Cart + Checkout -->
    <div class="col-lg-8">
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700"><i class="fa-solid fa-cart-shopping me-2 text-success"></i>Carrito de Venta</h6>

            <?php if (empty($carrito)): ?>
            <div class="empty-state"><i class="fa-solid fa-cart-shopping"></i>El carrito está vacío</div>
            <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table">
                    <thead><tr><th>Producto</th><th>Cant.</th><th class="text-end">Precio</th><th class="text-end">Subtotal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach($carrito as $key => $item):
                        $sub = $item['precio'] * $item['cantidad']; ?>
                    <tr<?= $item['obsequio'] ? ' style="background:#fdf2f8"' : '' ?>>
                        <td><?= h($item['nombre']) ?>
                            <?php if ($item['obsequio']): ?><span class="badge badge-gift ms-1"><i class="fa-solid fa-gift me-1"></i>Obsequio</span><?php endif; ?></td>
                        <td><?= $item['cantidad'] ?></td>
                        <td class="text-end"><?php if ($item['obsequio']): ?><s class="text-muted me-1" style="font-size:.78rem">$<?= number_format($item['pvp'],2) ?></s><?php endif; ?>$<?= number_format($item['precio'],2) ?></td>
                        <td class="text-end fw-600" style="font-weight:600">$<?= number_format($sub,2) ?></td>
                        <td class="text-end"><a href="?eliminar=<?= h($key) ?>" class="btn btn-xs btn-outline-danger" data-confirm="¿Quitar «<?= h($item['nombre']) ?>» del carrito?" data-confirm-btn="Sí, quitar"><i class="fa-solid fa-xmark"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" id="formVenta">
                <?= csrf_field() ?>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label">Cliente</label>
                        <select name="id_cliente" class="form-select" id="selCliente" required>
                            <?php foreach($clientes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $sel_cliente == $c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?> (<?= h($c['documento']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Forma de pago</label>
                        <select name="estado" id="estadoVenta" class="form-select" onchange="actualizarPago()" required>
                            <option value="Efectivo">Efectivo</option>
                            <option value="Transferencia">Transferencia</option>
                            <option value="Tarjeta">Tarjeta (Datáfono)</option>
                            <option value="Terceros">Terceros (Addi, Sistecrédito…)</option>
                            <option value="Credito Interno">Crédito Interno</option>
                            <option value="Mixto">Pago Mixto</option>
                        </select>
                        <div id="hintPago" class="mt-1" style="font-size:.75rem;color:#64748b"></div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Descuento ($)</label>
                        <input type="number" name="descuento" id="descuento" class="form-control" value="0" min="0" step="0.01" oninput="recalcTotal()">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Observación (auditoría)</label>
                        <input type="text" name="observacion" class="form-control" placeholder="Opcional">
                    </div>
                </div>

                <!-- Mixto block -->
                <div id="bloqueMixto" class="mt-3 p-3 bg-light rounded-3" style="display:none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-600" style="font-weight:600">Distribución del pago</span>
                        <button type="button" class="btn btn-xs btn-outline-primary" onclick="addPago()">+ Agregar método</button>
                    </div>
                    <div id="listaPagos">
                        <div class="row g-2 mb-2">
                            <div class="col-7"><select name="pagos[0][metodo]" class="form-select form-select-sm metodo-mixto"><?php foreach ($metodos_mixto as $v => $l): ?><option value="<?= h($v) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
                            <div class="col-5"><input type="number" name="pagos[0][monto]" class="form-control form-control-sm" placeholder="Monto" step="0.01" min="0.01"></div>
                        </div>
                    </div>
                </div>

                <!-- Terceros: entidad -->
                <div id="bloqueTerceros" class="mt-3 p-3 rounded-3" style="display:none;background:#eff6ff;border:1px solid #bfdbfe">
                    <div class="mb-2" style="font-weight:600;color:#1d4ed8"><i class="fa-solid fa-building-columns me-2"></i>Pago por Terceros</div>
                    <div class="row g-2">
                        <div class="col-sm-4">
                            <label class="form-label">Entidad *</label>
                            <select name="entidad" id="selEntidad" class="form-select" onchange="actualizarPago()">
                                <option value="">Seleccione…</option>
                                <?php foreach (ENTIDADES_TERCEROS as $e): ?><option value="<?= h($e) ?>"><?= h($e) ?></option><?php endforeach; ?>
                                <option value="Otra">Otra…</option>
                            </select>
                        </div>
                        <div class="col-sm-4" id="colEntidadOtra" style="display:none">
                            <label class="form-label">¿Cuál entidad? *</label>
                            <input type="text" name="entidad_otra" id="entidadOtra" class="form-control" maxlength="100" placeholder="Nombre de la entidad">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Ref. de aprobación</label>
                            <input type="text" name="referencia" class="form-control" maxlength="100" placeholder="Opcional">
                        </div>
                    </div>
                    <div class="mt-2" style="font-size:.75rem;color:#64748b">El valor queda en Cartera hasta que la entidad pague; regístralo allí como abono.</div>
                </div>

                <!-- Crédito Interno: quién autoriza -->
                <div id="bloqueCredito" class="mt-3 p-3 rounded-3" style="display:none;background:#faf5ff;border:1px solid #e9d5ff">
                    <div class="mb-2" style="font-weight:600;color:#7e22ce"><i class="fa-solid fa-user-shield me-2"></i>Crédito Interno</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6">
                            <label class="form-label">Autorizado por *</label>
                            <select name="id_autoriza" id="selAutoriza" class="form-select">
                                <option value="">Seleccione quién autoriza…</option>
                                <?php foreach ($autorizadores as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= h($u['nombre']) ?> (<?= $u['rol'] === 'admin' ? 'Administrador' : 'Vendedor' ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6" style="font-size:.75rem;color:#64748b">El saldo queda en Cartera a nombre del cliente y se cobra con abonos. No se permite a nombre de <i>Cliente General</i>.</div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3" style="border-top:2px solid #e2e8f0">
                    <div>
                        <div style="font-size:.8rem;color:#64748b">Subtotal: $<?= number_format($subtotal,2) ?></div>
                        <div id="totalLabel" class="fw-800" style="font-size:1.5rem;font-weight:800;color:#1e293b">Total: $<?= number_format($subtotal,2) ?></div>
                    </div>
                    <button type="submit" name="procesar_venta" class="btn btn-success btn-lg px-5" <?= $caja ? '' : 'disabled title="Abre la caja para poder cobrar"' ?>>
                        <i class="fa-solid fa-check me-2"></i>Cobrar
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const subtotalBase = <?= (float)$subtotal ?>;
let pagoIdx = 1;

function recalcTotal() {
    const desc = Math.max(0, parseFloat(document.getElementById('descuento').value)||0);
    const total = Math.max(0, subtotalBase - desc);
    document.getElementById('totalLabel').textContent = 'Total: $' + total.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

const METODOS_MIXTO = <?= json_encode($metodos_mixto, JSON_UNESCAPED_UNICODE) ?>;
const HINTS = {
    'Efectivo': 'Entra a caja.', 'Transferencia': 'Entra a caja.', 'Tarjeta': 'Entra a caja.',
    'Terceros': 'No entra a caja: queda en Cartera hasta que la entidad pague.',
    'Credito Interno': 'No entra a caja: queda en Cartera a nombre del cliente.',
    'Mixto': 'Reparte el total entre varios métodos.'
};

// Muestra los datos que exige cada método (también si van dentro de un pago mixto)
function actualizarPago() {
    const estado = document.getElementById('estadoVenta').value;
    const metodos = estado === 'Mixto'
        ? Array.from(document.querySelectorAll('.metodo-mixto')).map(s => s.value)
        : [estado];
    const terceros = metodos.includes('Terceros'), credito = metodos.includes('Credito Interno');
    document.getElementById('hintPago').textContent = HINTS[estado] || '';
    document.getElementById('bloqueMixto').style.display    = estado === 'Mixto' ? 'block' : 'none';
    document.getElementById('bloqueTerceros').style.display = terceros ? 'block' : 'none';
    document.getElementById('bloqueCredito').style.display  = credito ? 'block' : 'none';
    const otra = terceros && document.getElementById('selEntidad').value === 'Otra';
    document.getElementById('colEntidadOtra').style.display = otra ? 'block' : 'none';
    document.getElementById('selEntidad').required  = terceros;
    document.getElementById('entidadOtra').required = otra;
    document.getElementById('selAutoriza').required = credito;
}

function addPago() {
    const div = document.createElement('div');
    div.className = 'row g-2 mb-2';
    const opts = Object.entries(METODOS_MIXTO).map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
    div.innerHTML = `<div class="col-7"><select name="pagos[${pagoIdx}][metodo]" class="form-select form-select-sm metodo-mixto">${opts}</select></div><div class="col-5"><input type="number" name="pagos[${pagoIdx}][monto]" class="form-control form-control-sm" placeholder="Monto" step="0.01" min="0.01"></div>`;
    document.getElementById('listaPagos').appendChild(div);
    pagoIdx++;
}
document.addEventListener('change', e => { if (e.target.classList.contains('metodo-mixto')) actualizarPago(); });
if (document.getElementById('estadoVenta')) actualizarPago();

// Product filter
document.getElementById('buscarProd')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    const sel = document.getElementById('selectProd');
    Array.from(sel.options).forEach(o => {
        o.style.display = o.text.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Pre-select cliente after quick save
<?php if($sel_cliente): ?>
document.addEventListener('DOMContentLoaded', () => {
    const s = document.getElementById('selCliente');
    if(s) s.value = '<?= (int)$sel_cliente ?>';
});
<?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>
