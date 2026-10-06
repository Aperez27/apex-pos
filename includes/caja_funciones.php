<?php
// Sesiones de caja: una caja se abre con una base en efectivo y queda abierta (aunque cambie el día)
// hasta que alguien la cierra a mano. Todo el dinero que entra o sale mientras está abierta
// (ventas, abonos, ingresos y egresos manuales) queda ligado a ella por id_caja.

// Caja abierta actual o null
function caja_abierta(PDO $pdo): ?array {
    $c = $pdo->query("SELECT c.*, u.nombre AS usuario_apertura FROM cajas c LEFT JOIN usuarios u ON c.id_usuario_apertura=u.id
        WHERE c.estado='Abierta' ORDER BY c.id DESC LIMIT 1")->fetch();
    return $c ?: null;
}

// Dentro de una transacción: bloquea la fila de la caja abierta hasta el commit. Así un cierre no se
// cuela a mitad de una venta, abono o movimiento (ni estos quedan en una caja que ya se cerró).
function caja_abierta_bloqueada(PDO $pdo): ?array {
    $id = $pdo->query("SELECT id FROM cajas WHERE estado='Abierta' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
    return $id ? caja_get($pdo, (int)$id) : null;
}

// Abre una caja nueva; devuelve [ok, mensaje]. El bloqueo con nombre evita abrir dos cajas a la vez.
function caja_abrir(PDO $pdo, float $monto, string $obs): array {
    $pdo->query("SELECT GET_LOCK('apex_abrir_caja', 10)");
    try {
        if (caja_abierta($pdo)) return [false, "Ya hay una caja abierta. Ciérrala antes de abrir otra."];
        $pdo->prepare("INSERT INTO cajas (id_usuario_apertura,monto_inicial,obs_apertura) VALUES (?,?,?)")
            ->execute([$_SESSION['id_usuario'], max(0, $monto), $obs !== '' ? mb_substr($obs, 0, 500) : null]);
        return [true, "Caja abierta con una base de $" . number_format(max(0, $monto), 2) . " en efectivo."];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('apex_abrir_caja')");
    }
}

function caja_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT c.*, ua.nombre AS usuario_apertura, uc.nombre AS usuario_cierre FROM cajas c
        LEFT JOIN usuarios ua ON c.id_usuario_apertura=ua.id LEFT JOIN usuarios uc ON c.id_usuario_cierre=uc.id WHERE c.id=?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Todo lo que pasó en una caja: ventas con sus productos y pagos, abonos, movimientos manuales y cuadre
function caja_resumen(PDO $pdo, array $caja): array {
    $id = (int)$caja['id'];
    $q = function (string $sql) use ($pdo, $id) { $st = $pdo->prepare($sql); $st->execute([$id]); return $st->fetchAll(); };

    $ventas = $q("SELECT v.*, c.nombre AS cliente, c.documento, u.nombre AS vendedor, ua.nombre AS autoriza
        FROM ventas v LEFT JOIN clientes c ON v.id_cliente=c.id LEFT JOIN usuarios u ON v.id_usuario=u.id
        LEFT JOIN usuarios ua ON v.id_autoriza=ua.id WHERE v.id_caja=? ORDER BY v.fecha, v.id");
    $detalles = $q("SELECT d.*, IFNULL(p.nombre,'(producto eliminado)') AS nombre, p.color FROM detalles_venta d
        JOIN ventas v ON d.id_venta=v.id LEFT JOIN productos p ON d.id_producto=p.id WHERE v.id_caja=? ORDER BY d.id");
    $pagos = $q("SELECT pv.* FROM pagos_venta pv JOIN ventas v ON pv.id_venta=v.id WHERE v.id_caja=? ORDER BY pv.id");
    $abonos = $q("SELECT a.*, c.nombre AS cliente, u.nombre AS usuario FROM abonos a JOIN ventas v ON a.id_venta=v.id
        LEFT JOIN clientes c ON v.id_cliente=c.id LEFT JOIN usuarios u ON a.id_usuario=u.id WHERE a.id_caja=? ORDER BY a.fecha, a.id");
    $manuales = $q("SELECT m.*, u.nombre AS usuario FROM caja_chica m LEFT JOIN usuarios u ON m.id_usuario=u.id
        WHERE m.id_caja=? AND (m.referencia_origen='Manual' OR m.ajusta_caja IS NOT NULL) ORDER BY m.fecha, m.id");
    $movs = $q("SELECT tipo, IFNULL(metodo,'Efectivo') AS metodo, monto, referencia_origen FROM caja_chica WHERE id_caja=?");

    foreach ($ventas as &$v) { $v['items'] = []; $v['pagos'] = []; }
    unset($v);
    $idx = array_flip(array_column($ventas, 'id'));
    foreach ($detalles as $d) $ventas[$idx[$d['id_venta']]]['items'][] = $d;
    foreach ($pagos as $p)    $ventas[$idx[$p['id_venta']]]['pagos'][] = $p;

    // Dinero real por medio (Efectivo / Transferencia / Tarjeta) y por origen
    $medios = [];
    foreach (METODOS_CAJA as $m) $medios[$m] = ['ventas' => 0.0, 'abonos' => 0.0, 'ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
    foreach ($movs as $mv) {
        $m = $mv['metodo'];
        if (!isset($medios[$m])) $medios[$m] = ['ventas' => 0.0, 'abonos' => 0.0, 'ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
        $monto = (float)$mv['monto'];
        if ($mv['tipo'] === 'Egreso') { $medios[$m]['egresos'] += $monto; $medios[$m]['neto'] -= $monto; continue; }
        $origen = str_starts_with((string)$mv['referencia_origen'], 'Venta') ? 'ventas'
            : (str_starts_with((string)$mv['referencia_origen'], 'Abono') ? 'abonos' : 'ingresos');
        $medios[$m][$origen] += $monto; $medios[$m]['neto'] += $monto;
    }

    // Ventas por forma de pago (incluye lo que queda en cartera: Terceros y Crédito Interno)
    $por_forma = []; $terceros_entidad = [];
    foreach ($pagos as $p) {
        $por_forma[$p['metodo']] = ($por_forma[$p['metodo']] ?? 0) + (float)$p['monto'];
        if ($p['metodo'] === 'Terceros') {
            $ent = $p['entidad'] ?: 'Sin entidad';
            $terceros_entidad[$ent] = ($terceros_entidad[$ent] ?? 0) + (float)$p['monto'];
        }
    }

    // Productos vendidos consolidados
    $productos = [];
    foreach ($detalles as $d) {
        $k = producto_label($d['nombre'], $d['color']);
        $productos[$k] ??= ['nombre' => $k, 'vendidos' => 0, 'obsequios' => 0, 'total' => 0.0];
        if ($d['es_obsequio']) $productos[$k]['obsequios'] += (int)$d['cantidad'];
        else { $productos[$k]['vendidos'] += (int)$d['cantidad']; $productos[$k]['total'] += (float)$d['subtotal']; }
    }
    uasort($productos, fn($a, $b) => $b['total'] <=> $a['total']);

    $base = (float)$caja['monto_inicial'];
    $ef = $medios['Efectivo'];
    $esperado = round($base + $ef['neto'], 2);

    return [
        'ventas'      => $ventas,
        'abonos'      => $abonos,
        'manuales'    => $manuales,
        'medios'      => $medios,
        'por_forma'   => $por_forma,
        'terceros_entidad' => $terceros_entidad,
        'productos'   => array_values($productos),
        'num_ventas'  => count($ventas),
        'subtotal'    => array_sum(array_map(fn($v) => (float)$v['subtotal'], $ventas)),
        'descuentos'  => array_sum(array_map(fn($v) => (float)$v['descuento'], $ventas)),
        'total_ventas'=> array_sum(array_map(fn($v) => (float)$v['total'], $ventas)),
        'cartera'     => array_sum(array_intersect_key($por_forma, array_flip(METODOS_CARTERA))),
        'total_abonos'=> array_sum(array_map(fn($a) => (float)$a['monto'], $abonos)),
        'unidades'    => array_sum(array_column($productos, 'vendidos')),
        'obsequios'   => array_sum(array_column($productos, 'obsequios')),
        'base'        => $base,
        'efectivo_esperado' => $esperado,
        'cuentas'     => round(array_sum(array_map(fn($m) => $m['neto'], array_diff_key($medios, ['Efectivo' => 1]))), 2),
    ];
}

// Descuadres: cajas cerradas cuyo efectivo contado no coincidió con el esperado. Un faltante se
// cuadra con ingresos manuales ligados a esa caja (ajusta_caja) y un sobrante con egresos; el
// descuadre sigue "Pendiente" hasta que los ajustes cubren la diferencia.
function caja_descuadres(PDO $pdo, ?int $id_caja = null): array {
    $sql = "SELECT c.id, c.fecha_apertura, c.fecha_cierre, c.efectivo_esperado, c.efectivo_contado, c.obs_cierre,
            ua.nombre AS usuario_apertura, uc.nombre AS usuario_cierre,
            IFNULL((SELECT SUM(m.monto) FROM caja_chica m WHERE m.ajusta_caja=c.id
                AND m.tipo = IF(c.efectivo_contado < c.efectivo_esperado, 'Ingreso', 'Egreso')), 0) AS ajustado,
            (SELECT MAX(m.fecha) FROM caja_chica m WHERE m.ajusta_caja=c.id) AS ultimo_ajuste
        FROM cajas c LEFT JOIN usuarios ua ON c.id_usuario_apertura=ua.id LEFT JOIN usuarios uc ON c.id_usuario_cierre=uc.id
        WHERE c.estado='Cerrada' AND ABS(c.efectivo_contado - c.efectivo_esperado) >= 0.01" . ($id_caja ? " AND c.id=?" : '') . "
        ORDER BY c.fecha_cierre DESC, c.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($id_caja ? [$id_caja] : []);
    return array_map(function ($d) {
        $dif = round((float)$d['efectivo_contado'] - (float)$d['efectivo_esperado'], 2);
        $d['tipo']      = $dif < 0 ? 'Faltante' : 'Sobrante';
        $d['monto']     = abs($dif);
        $d['ajustado']  = (float)$d['ajustado'];
        $d['pendiente'] = max(0, round($d['monto'] - $d['ajustado'], 2));
        $d['estado']    = $d['pendiente'] < 0.01 ? 'Cuadrado' : 'Pendiente';
        return $d;
    }, $st->fetchAll());
}

function caja_descuadres_pendientes(PDO $pdo): array {
    return array_values(array_filter(caja_descuadres($pdo), fn($d) => $d['estado'] === 'Pendiente'));
}

// Movimientos que ajustan el descuadre de una caja
function caja_ajustes(PDO $pdo, int $id_caja): array {
    $st = $pdo->prepare("SELECT m.*, u.nombre AS usuario FROM caja_chica m LEFT JOIN usuarios u ON m.id_usuario=u.id
        WHERE m.ajusta_caja=? ORDER BY m.fecha, m.id");
    $st->execute([$id_caja]);
    return $st->fetchAll();
}
