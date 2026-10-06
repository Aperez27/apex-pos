<?php
require 'config/conexion.php';
if ($rol !== 'admin') { include 'includes/header.php'; echo '<div class="alert alert-danger">Acceso denegado.</div>'; include 'includes/footer.php'; exit; }

// Id de categoría/proveedor si existe; 0 si no (queda "Sin categoría" / "Sin proveedor")
function id_valido(PDO $pdo, string $tabla, int $id): int {
    if ($id <= 0) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$tabla}` WHERE id=?");
    $st->execute([$id]);
    return $st->fetchColumn() ? $id : 0;
}

// Add product
if (isset($_POST['guardar_producto'])) {
    csrf_verify();
    $nombre  = trim($_POST['nombre']);
    $precio_c = max(0, (float)($_POST['precio_compra'] ?? 0));
    $precio_v = (float)($_POST['precio_venta'] ?? 0);
    $id_cat   = id_valido($pdo, 'categorias', (int)($_POST['id_categoria'] ?? 0));
    $id_prov  = id_valido($pdo, 'proveedores', (int)($_POST['id_proveedor'] ?? 0));
    if ($nombre === '' || $precio_v <= 0) flash_redirect('productos.php', "Nombre y precio de venta son obligatorios.", 'danger');

    // Filas color/stock: cada color se guarda como un producto con su propio stock
    $colores = (array)($_POST['color'] ?? ['']);
    $stocks  = (array)($_POST['stock'] ?? [0]);
    $filas = [];
    foreach ($colores as $i => $c) {
        $c = mb_substr(trim((string)$c), 0, 40);
        $filas[] = [$c, max(0, (int)($stocks[$i] ?? 0))];
    }
    if (count($filas) > 1 && in_array('', array_column($filas, 0), true)) {
        flash_redirect('productos.php', "Indica el color de cada fila (o deja una sola fila sin color).", 'danger');
    }
    $claves = array_map('mb_strtolower', array_column($filas, 0));
    if (count($claves) !== count(array_unique($claves))) flash_redirect('productos.php', "Hay colores repetidos.", 'danger');
    $existe = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE nombre=? AND IFNULL(color,'')=?");
    foreach ($filas as [$c]) {
        $existe->execute([$nombre, $c]);
        if ($existe->fetchColumn() > 0) {
            flash_redirect('productos.php', "Ya existe «" . producto_label($nombre, $c) . "». Usa el botón + para agregarle stock.", 'warning');
        }
    }
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO productos (nombre,color,id_categoria,id_proveedor,precio_compra,precio_venta,stock) VALUES (?,?,?,?,?,?,?)");
    foreach ($filas as [$c, $st]) $ins->execute([$nombre, $c ?: null, $id_cat ?: null, $id_prov ?: null, $precio_c, $precio_v, $st]);
    $pdo->commit();
    $n = count($filas);
    flash_redirect('productos.php', $n > 1 ? "Producto «{$nombre}» guardado en {$n} colores." : "Producto «" . producto_label($nombre, $filas[0][0]) . "» guardado correctamente.");
}

// Edit product
if (isset($_POST['editar_producto'])) {
    csrf_verify();
    $id       = (int)$_POST['id'];
    $nombre   = trim($_POST['nombre']);
    $precio_c = max(0, (float)($_POST['precio_compra'] ?? 0));
    $precio_v = (float)($_POST['precio_venta'] ?? 0);
    $id_cat   = id_valido($pdo, 'categorias', (int)($_POST['id_categoria'] ?? 0));
    $id_prov  = id_valido($pdo, 'proveedores', (int)($_POST['id_proveedor'] ?? 0));
    $color    = mb_substr(trim((string)($_POST['color'] ?? '')), 0, 40);
    if ($nombre !== '' && $precio_v > 0) {
        $existe = $pdo->prepare("SELECT COUNT(*) FROM productos WHERE nombre=? AND IFNULL(color,'')=? AND id<>?");
        $existe->execute([$nombre, $color, $id]);
        if ($existe->fetchColumn() > 0) flash_redirect('productos.php', "Ya existe otro producto «" . producto_label($nombre, $color) . "».", 'warning');
        $pdo->prepare("UPDATE productos SET nombre=?,color=?,id_categoria=?,id_proveedor=?,precio_compra=?,precio_venta=? WHERE id=?")
            ->execute([$nombre, $color ?: null, $id_cat ?: null, $id_prov ?: null, $precio_c, $precio_v, $id]);
        flash_redirect('productos.php', "Producto actualizado.");
    }
    flash_redirect('productos.php', "Datos inválidos.", 'danger');
}

// Restock
if (isset($_POST['restock'])) {
    csrf_verify();
    $id   = (int)$_POST['id'];
    $cant = max(1, (int)$_POST['cantidad_restock']);
    $pdo->prepare("UPDATE productos SET stock=stock+? WHERE id=?")->execute([$cant, $id]);
    flash_redirect('productos.php', "Stock actualizado (+{$cant} unidades).");
}

// New category
if (isset($_POST['guardar_categoria'])) {
    csrf_verify();
    $cat = trim($_POST['nombre_categoria']);
    if ($cat !== '') {
        $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([$cat]);
        flash_redirect('productos.php', "Categoría «{$cat}» creada.");
    }
    flash_redirect('productos.php', "El nombre de la categoría es obligatorio.", 'danger');
}

// Rename category
if (isset($_POST['editar_categoria'])) {
    csrf_verify();
    $id  = (int)$_POST['id_categoria'];
    $cat = trim($_POST['nombre_categoria']);
    if ($cat === '') flash_redirect('productos.php', "El nombre de la categoría es obligatorio.", 'danger');
    $pdo->prepare("UPDATE categorias SET nombre=? WHERE id=?")->execute([$cat, $id]);
    flash_redirect('productos.php', "Categoría renombrada a «{$cat}».");
}

// Delete category: sus productos quedan "Sin categoría" (no se borra ningún producto)
if (isset($_POST['eliminar_categoria'])) {
    csrf_verify();
    $id = (int)$_POST['eliminar_categoria'];
    $st = $pdo->prepare("SELECT nombre FROM categorias WHERE id=?");
    $st->execute([$id]);
    $cat = $st->fetchColumn();
    if ($cat === false) flash_redirect('productos.php', "La categoría no existe.", 'danger');
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE productos SET id_categoria=NULL WHERE id_categoria=?");
    $upd->execute([$id]);
    $pdo->prepare("DELETE FROM categorias WHERE id=?")->execute([$id]);
    $pdo->commit();
    $n = $upd->rowCount();
    flash_redirect('productos.php', "Categoría «{$cat}» eliminada." . ($n ? " {$n} producto(s) quedaron sin categoría." : ''));
}

// Delete product
if (isset($_POST['eliminar'])) {
    csrf_verify();
    $id = (int)$_POST['eliminar'];
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM detalles_venta WHERE id_producto=?) + (SELECT COUNT(*) FROM inventario_movimientos WHERE id_producto=?)");
    $st->execute([$id, $id]);
    if ((int)$st->fetchColumn() > 0) {
        flash_redirect('productos.php', "No se puede eliminar: el producto tiene ventas o movimientos registrados. Puedes dejar su stock en 0.", 'danger');
    }
    $pdo->prepare("DELETE FROM productos WHERE id=?")->execute([$id]);
    flash_redirect('productos.php', "Producto eliminado.");
}
[$msg, $msg_type] = flash_get();

$productos   = $pdo->query("SELECT p.*,c.nombre as cat FROM productos p LEFT JOIN categorias c ON p.id_categoria=c.id ORDER BY p.nombre, p.color")->fetchAll();
$categorias  = $pdo->query("SELECT * FROM categorias ORDER BY nombre")->fetchAll();
$proveedores = $pdo->query("SELECT * FROM proveedores ORDER BY nombre")->fetchAll();

$buscar = trim($_GET['q'] ?? '');
if ($buscar !== '') {
    $productos = array_filter($productos, fn($p) => stripos($p['nombre'], $buscar) !== false || stripos($p['cat'] ?? '', $buscar) !== false || stripos($p['color'] ?? '', $buscar) !== false);
}
include 'includes/header.php';
?>

<div class="page-header">
    <div><h1>Inventario</h1><p>Gestiona productos, precios y stock</p></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregar">
        <i class="fa-solid fa-plus me-1"></i>Nuevo Producto
    </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg_type ?> alert-dismiss mb-3"><?= h($msg) ?></div><?php endif; ?>

<div class="row g-3">
    <!-- Table -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-header-custom d-flex align-items-center gap-3">
                <form method="GET" class="d-flex gap-2 flex-fill">
                    <input type="text" name="q" class="form-control" placeholder="Buscar producto, color o categoría..." value="<?= h($buscar) ?>">
                    <button type="submit" class="btn btn-outline-secondary">Buscar</button>
                    <?php if($buscar): ?><a href="productos.php" class="btn btn-outline-secondary">✕</a><?php endif; ?>
                </form>
                <span class="text-muted small"><?= count($productos) ?> productos</span>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Color</th>
                            <th>Categoría</th>
                            <th>Costo</th>
                            <th>PVP</th>
                            <th>Margen</th>
                            <th>Stock</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($productos)): ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="fa-solid fa-box-open"></i>No hay productos</div></td></tr>
                        <?php else: foreach ($productos as $p):
                            $margen = $p['precio_compra'] > 0 ? round(($p['precio_venta'] - $p['precio_compra']) / $p['precio_venta'] * 100, 1) : 0;
                            $stockClass = $p['stock'] <= 0 ? 'badge-danger' : ($p['stock'] <= 5 ? 'badge-warning' : 'badge-success');
                        ?>
                        <tr>
                            <td class="fw-600" style="font-weight:600"><?= h($p['nombre']) ?></td>
                            <td><?= color_badge($p['color']) ?></td>
                            <td><span class="badge badge-secondary"><?= h($p['cat'] ?? '—') ?></span></td>
                            <td style="color:#64748b">$<?= number_format($p['precio_compra'],2) ?></td>
                            <td class="fw-600" style="font-weight:600;color:#2563eb">$<?= number_format($p['precio_venta'],2) ?></td>
                            <td><span class="badge badge-info"><?= $margen ?>%</span></td>
                            <td><span class="badge <?= $stockClass ?>"><?= $p['stock'] ?> und.</span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-xs btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#modalEditar"
                                        data-id="<?= $p['id'] ?>"
                                        data-nombre="<?= h($p['nombre']) ?>"
                                        data-color="<?= h($p['color'] ?? '') ?>"
                                        data-precio_compra="<?= $p['precio_compra'] ?>"
                                        data-precio_venta="<?= $p['precio_venta'] ?>"
                                        data-id_categoria="<?= $p['id_categoria'] ?>"
                                        data-id_proveedor="<?= $p['id_proveedor'] ?>"
                                        title="Editar"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn btn-xs btn-outline-success"
                                        data-bs-toggle="modal" data-bs-target="#modalRestock"
                                        data-id="<?= $p['id'] ?>"
                                        data-nombre="<?= h(producto_label($p['nombre'], $p['color'])) ?>"
                                        title="Agregar stock"><i class="fa-solid fa-plus"></i></button>
                                    <?= delete_button((int)$p['id'], "¿Eliminar «" . producto_label($p['nombre'], $p['color']) . "»? Esta acción no se puede deshacer.") ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Side: Categories -->
    <div class="col-lg-3">
        <div class="card p-4">
            <h6 class="fw-700 mb-3" style="font-weight:700">Categorías</h6>
            <?php
            $cat_counts = [];
            foreach($pdo->query("SELECT id_categoria, COUNT(*) as c FROM productos GROUP BY id_categoria")->fetchAll() as $r)
                $cat_counts[(int)$r['id_categoria']] = (int)$r['c'];
            foreach($categorias as $c): ?>
            <?php $nprod = $cat_counts[(int)$c['id']] ?? 0; ?>
            <div class="d-flex align-items-center gap-1 py-1" style="border-bottom:1px solid #f1f5f9">
                <span class="flex-fill" style="font-size:.85rem"><?= h($c['nombre']) ?></span>
                <span class="badge badge-secondary me-1" style="font-size:.68rem" title="Productos"><?= $nprod ?></span>
                <button type="button" class="btn btn-xs btn-outline-primary" title="Renombrar"
                    data-bs-toggle="modal" data-bs-target="#modalCategoria"
                    data-id="<?= $c['id'] ?>" data-nombre="<?= h($c['nombre']) ?>"><i class="fa-solid fa-pen"></i></button>
                <?= delete_button((int)$c['id'], "¿Eliminar la categoría «{$c['nombre']}»?" . ($nprod ? " Sus {$nprod} producto(s) no se borran: quedan «Sin categoría»." : ''), 'eliminar_categoria') ?>
            </div>
            <?php endforeach; ?>
            <form method="POST" class="mt-3">
                <?= csrf_field() ?>
                <div class="input-group">
                    <input type="text" name="nombre_categoria" class="form-control form-control-sm" placeholder="Nueva categoría" required>
                    <button type="submit" name="guardar_categoria" class="btn btn-sm btn-primary">+</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Agregar Producto -->
<div class="modal fade" id="modalAgregar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-700" style="font-weight:700">Nuevo Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <div class="modal-body pt-3">
                    <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Categoría</label>
                            <select name="id_categoria" class="form-select">
                                <option value="">Sin categoría</option>
                                <?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['nombre']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Proveedor</label>
                            <select name="id_proveedor" class="form-select">
                                <option value="">Sin proveedor</option>
                                <?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['nombre'] . (trim((string)$p['observacion']) !== '' ? ' — ' . mb_strimwidth($p['observacion'], 0, 40, '…') : '')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3"><label class="form-label">Costo ($) *</label><input type="number" name="precio_compra" class="form-control" step="0.01" min="0" value="0" required></div>
                        <div class="col-6 mb-3"><label class="form-label">PVP ($) *</label><input type="number" name="precio_venta" class="form-control" step="0.01" min="0.01" value="0" required></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">Colores y stock inicial</label>
                        <button type="button" class="btn btn-xs btn-outline-primary" onclick="addColorRow()"><i class="fa-solid fa-plus me-1"></i>Agregar color</button>
                    </div>
                    <div id="colorRows">
                        <div class="row g-2 mb-2 color-row">
                            <div class="col-7"><div class="input-group">
                                <span class="input-group-text"><span class="color-dot" style="background:#fff"></span></span>
                                <input type="text" name="color[]" class="form-control color-input" list="listaColores" maxlength="40" placeholder="Sin color" autocomplete="off">
                            </div></div>
                            <div class="col-4"><input type="number" name="stock[]" class="form-control" min="0" value="0" required title="Stock inicial"></div>
                            <div class="col-1 d-flex align-items-center"><button type="button" class="btn btn-xs btn-outline-danger color-del" onclick="delColorRow(this)" title="Quitar" style="visibility:hidden">✕</button></div>
                        </div>
                    </div>
                    <p class="small text-muted mb-0">Cada color queda como un producto con su propio stock. Deja el color vacío si el producto no maneja colores.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_producto" class="btn btn-primary">Guardar Producto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Producto -->
<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-700" style="font-weight:700">Editar Producto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body pt-3">
                    <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" id="edit_nombre" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Color</label><div class="input-group">
                        <span class="input-group-text"><span class="color-dot" style="background:#fff"></span></span>
                        <input type="text" name="color" id="edit_color" class="form-control color-input" list="listaColores" maxlength="40" placeholder="Sin color" autocomplete="off">
                    </div></div>
                    <div class="row g-2">
                        <div class="col-6 mb-3">
                            <label class="form-label">Categoría</label>
                            <select name="id_categoria" id="edit_cat" class="form-select">
                                <option value="">Sin categoría</option>
                                <?php foreach($categorias as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['nombre']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Proveedor</label>
                            <select name="id_proveedor" id="edit_prov" class="form-select">
                                <option value="">Sin proveedor</option>
                                <?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>"><?= h($p['nombre'] . (trim((string)$p['observacion']) !== '' ? ' — ' . mb_strimwidth($p['observacion'], 0, 40, '…') : '')) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6 mb-3"><label class="form-label">Costo ($)</label><input type="number" name="precio_compra" id="edit_costo" class="form-control" step="0.01" min="0"></div>
                        <div class="col-6 mb-3"><label class="form-label">PVP ($) *</label><input type="number" name="precio_venta" id="edit_pvp" class="form-control" step="0.01" min="0.01" required></div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_producto" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Renombrar categoría -->
<div class="modal fade" id="modalCategoria" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" style="font-weight:700">Renombrar categoría</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id_categoria" id="cat_id">
                <div class="modal-body">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="nombre_categoria" id="cat_nombre" class="form-control" maxlength="100" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="editar_categoria" class="btn btn-primary btn-sm">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Restock -->
<div class="modal fade" id="modalRestock" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="border-radius:16px">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-700" style="font-weight:700">Agregar Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="id" id="restock_id">
                <div class="modal-body">
                    <p class="text-muted small" id="restock_nombre"></p>
                    <label class="form-label">Unidades a agregar *</label>
                    <input type="number" name="cantidad_restock" class="form-control" min="1" value="1" required>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="restock" class="btn btn-success btn-sm">Agregar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<datalist id="listaColores">
    <?php foreach (COLORES as $nombreColor => $hex): ?><option value="<?= h($nombreColor) ?>"><?php endforeach; ?>
</datalist>

<script>
const COLORES = <?= json_encode(COLORES, JSON_UNESCAPED_UNICODE) ?>;
function colorHex(nombre) {
    const k = Object.keys(COLORES).find(c => c.toLowerCase() === nombre.trim().toLowerCase());
    return k ? COLORES[k] : (nombre.trim() ? '#94a3b8' : '#fff');
}
function pintarColor(input) {
    input.closest('.input-group').querySelector('.color-dot').style.background = colorHex(input.value);
}
document.addEventListener('input', e => { if (e.target.classList.contains('color-input')) pintarColor(e.target); });
function addColorRow() {
    const rows = document.getElementById('colorRows');
    const row = rows.querySelector('.color-row').cloneNode(true);
    row.querySelector('.color-input').value = '';
    row.querySelector('input[name="stock[]"]').value = 0;
    row.querySelector('.color-dot').style.background = '#fff';
    rows.appendChild(row);
    actualizarFilas();
    row.querySelector('.color-input').focus();
}
function delColorRow(btn) { btn.closest('.color-row').remove(); actualizarFilas(); }
function actualizarFilas() {
    const rows = document.querySelectorAll('#colorRows .color-row');
    rows.forEach(r => {
        r.querySelector('.color-del').style.visibility = rows.length > 1 ? 'visible' : 'hidden';
        r.querySelector('.color-input').required = rows.length > 1;
        r.querySelector('.color-input').placeholder = rows.length > 1 ? 'Color' : 'Sin color';
    });
}
document.getElementById('modalEditar').addEventListener('show.bs.modal', function(e) {
    const b = e.relatedTarget;
    document.getElementById('edit_id').value     = b.dataset.id;
    document.getElementById('edit_nombre').value = b.dataset.nombre;
    document.getElementById('edit_color').value  = b.dataset.color;
    pintarColor(document.getElementById('edit_color'));
    document.getElementById('edit_costo').value  = b.dataset.precio_compra;
    document.getElementById('edit_pvp').value    = b.dataset.precio_venta;
    document.getElementById('edit_cat').value    = b.dataset.id_categoria;
    document.getElementById('edit_prov').value   = b.dataset.id_proveedor;
});
document.getElementById('modalCategoria').addEventListener('show.bs.modal', function(e) {
    document.getElementById('cat_id').value     = e.relatedTarget.dataset.id;
    document.getElementById('cat_nombre').value = e.relatedTarget.dataset.nombre;
});
document.getElementById('modalRestock').addEventListener('show.bs.modal', function(e) {
    const b = e.relatedTarget;
    document.getElementById('restock_id').value       = b.dataset.id;
    document.getElementById('restock_nombre').textContent = b.dataset.nombre;
});
</script>
<?php include 'includes/footer.php'; ?>
