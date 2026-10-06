<?php
// Instalador y actualizador de la base de datos de APEX.
// - Primera instalación: cualquiera puede ejecutarlo (aún no hay usuarios).
// - Sistema ya instalado: solo un administrador con sesión iniciada (o por consola: php setup.php),
//   para aplicar las actualizaciones de estructura de una nueva versión.
require 'config/config.php';

function setup_page(string $icon, string $title, string $body): void {
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1'>"
       . "<meta name='robots' content='noindex, nofollow'><title>" . APP_NAME . " · Instalación</title>"
       . "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css' rel='stylesheet'></head>"
       . "<body class='bg-light d-flex flex-column align-items-center justify-content-center min-vh-100 p-3'>"
       . "<div class='card p-5 text-center shadow' style='max-width:480px;border-radius:16px'>"
       . "<div class='mb-3' style='font-size:2.5rem'>{$icon}</div><h3 class='fw-bold'>{$title}</h3>{$body}</div>"
       . "<p class='text-muted small mt-3'>" . htmlspecialchars(app_copyright(), ENT_QUOTES, 'UTF-8') . "</p></body></html>";
}

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    app_session_start();
    $instalado = false; $es_admin = false;
    try {
        $p = app_pdo();
        $instalado = (int)$p->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() > 0;
        $st = $p->prepare("SELECT rol FROM usuarios WHERE id=?");
        $st->execute([$_SESSION['id_usuario'] ?? 0]);
        $es_admin = $st->fetchColumn() === 'admin';
    } catch (Throwable $e) { /* base aún no creada: primera instalación */ }
    if ($instalado && !$es_admin) {
        http_response_code(403);
        setup_page('🔒', APP_NAME . ' ya está instalado', "<p class='text-muted'>Para aplicar actualizaciones de la base de datos inicia sesión como administrador y vuelve a abrir esta página.</p><a href='index.php' class='btn btn-dark px-4 py-2 mt-2'>Ir al inicio de sesión</a>");
        exit;
    }
}

$usuarios_creados = false;
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL,
        usuario VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        rol ENUM('admin','vendedor') NOT NULL DEFAULT 'vendedor',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        documento VARCHAR(50) UNIQUE NOT NULL,
        telefono VARCHAR(25),
        email VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categorias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(100) NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(120) NOT NULL,
        contacto VARCHAR(100),
        telefono VARCHAR(25),
        email VARCHAR(100),
        observacion TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS productos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(180) NOT NULL,
        id_categoria INT,
        id_proveedor INT,
        precio_compra DECIMAL(12,2) DEFAULT 0,
        precio_venta DECIMAL(12,2) DEFAULT 0,
        stock INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ventas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        id_cliente INT,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        subtotal DECIMAL(12,2) DEFAULT 0,
        descuento DECIMAL(12,2) DEFAULT 0,
        total DECIMAL(12,2) NOT NULL,
        estado ENUM('Vendido','Devolucion','Fiado','Efectivo','Credito RP','Tarjeta','Transferencia','Terceros','Mixto') DEFAULT 'Vendido',
        entidad_financiera VARCHAR(100),
        referencia_credito VARCHAR(100),
        saldo_pendiente DECIMAL(12,2) DEFAULT 0,
        observacion TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS detalles_venta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_venta INT NOT NULL,
        id_producto INT NOT NULL,
        cantidad INT NOT NULL,
        precio_unitario DECIMAL(12,2) NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (id_venta) REFERENCES ventas(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS abonos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_venta INT NOT NULL,
        monto DECIMAL(12,2) NOT NULL,
        observacion TEXT,
        id_usuario INT NOT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_venta) REFERENCES ventas(id) ON DELETE CASCADE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS caja_chica (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        tipo ENUM('Ingreso','Egreso') NOT NULL,
        monto DECIMAL(12,2) NOT NULL,
        descripcion TEXT,
        referencia_origen VARCHAR(80) DEFAULT 'Manual',
        id_usuario INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventario_movimientos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_producto INT NOT NULL,
        tipo ENUM('Averiado','Obsequio') NOT NULL,
        cantidad INT NOT NULL,
        motivo TEXT,
        id_usuario INT NOT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracion (
        id INT PRIMARY KEY DEFAULT 1,
        nombre_empresa VARCHAR(150) DEFAULT 'APEX',
        nit VARCHAR(60),
        telefono VARCHAR(30),
        direccion TEXT,
        mensaje_ticket TEXT
    )");

    // Migraciones: columnas agregadas a tablas existentes (cada bloque es idempotente)
    try { $pdo->exec("ALTER TABLE proveedores ADD COLUMN email VARCHAR(100) AFTER telefono"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE ventas ADD COLUMN subtotal DECIMAL(12,2) DEFAULT 0 AFTER fecha"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE ventas ADD COLUMN observacion TEXT AFTER saldo_pendiente"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE abonos CHANGE COLUMN id_usuario id_usuario INT NOT NULL"); } catch(Exception $e) {}
    // Migración: color por producto y obsequios dentro de una venta
    try { $pdo->exec("ALTER TABLE productos ADD COLUMN color VARCHAR(40) NULL AFTER nombre"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE detalles_venta ADD COLUMN es_obsequio TINYINT(1) NOT NULL DEFAULT 0 AFTER subtotal"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE inventario_movimientos ADD COLUMN id_venta INT NULL AFTER motivo"); } catch(Exception $e) {}
    // Migración: formas de pago — Crédito Interno (con quién autoriza) reemplaza a Fiado; desglose de pagos por venta
    try { $pdo->exec("ALTER TABLE ventas MODIFY estado ENUM('Vendido','Devolucion','Fiado','Efectivo','Credito RP','Tarjeta','Transferencia','Terceros','Mixto','Credito Interno') DEFAULT 'Efectivo'"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE ventas ADD COLUMN id_autoriza INT NULL AFTER referencia_credito"); } catch(Exception $e) {}
    $pdo->exec("UPDATE ventas SET estado='Credito Interno' WHERE estado='Fiado'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos_venta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_venta INT NOT NULL,
        metodo VARCHAR(30) NOT NULL,
        monto DECIMAL(12,2) NOT NULL,
        entidad VARCHAR(100),
        FOREIGN KEY (id_venta) REFERENCES ventas(id) ON DELETE CASCADE
    )");
    // Migración: método con el que se recibe cada abono
    try { $pdo->exec("ALTER TABLE abonos ADD COLUMN metodo VARCHAR(30) NOT NULL DEFAULT 'Efectivo' AFTER monto"); } catch(Exception $e) {}
    // Migración: sesiones de caja (apertura → cierre manual) y a qué caja pertenece cada movimiento
    $pdo->exec("CREATE TABLE IF NOT EXISTS cajas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        estado ENUM('Abierta','Cerrada') NOT NULL DEFAULT 'Abierta',
        fecha_apertura DATETIME DEFAULT CURRENT_TIMESTAMP,
        id_usuario_apertura INT NOT NULL,
        monto_inicial DECIMAL(12,2) NOT NULL DEFAULT 0,
        obs_apertura TEXT,
        fecha_cierre DATETIME NULL,
        id_usuario_cierre INT NULL,
        efectivo_esperado DECIMAL(12,2) NULL,
        efectivo_contado DECIMAL(12,2) NULL,
        obs_cierre TEXT
    )");
    try { $pdo->exec("ALTER TABLE caja_chica ADD COLUMN metodo VARCHAR(30) NULL AFTER monto"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE caja_chica ADD COLUMN id_caja INT NULL AFTER id_usuario"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE ventas ADD COLUMN id_caja INT NULL AFTER id_cliente"); } catch(Exception $e) {}
    try { $pdo->exec("ALTER TABLE abonos ADD COLUMN id_caja INT NULL AFTER id_usuario"); } catch(Exception $e) {}
    // Migración: ajustes que cuadran el descuadre (faltante/sobrante) de una caja cerrada
    try { $pdo->exec("ALTER TABLE caja_chica ADD COLUMN ajusta_caja INT NULL AFTER id_caja"); } catch(Exception $e) {}
    // 1.0: obligar a cambiar la contraseña inicial del instalador en el primer ingreso
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0 AFTER rol"); } catch(Exception $e) {}
    // 1.0: registro de intentos fallidos de inicio de sesión (protección contra fuerza bruta)
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_intentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        usuario VARCHAR(50),
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_fecha (ip, fecha)
    )");
    try { $pdo->exec("ALTER TABLE configuracion ALTER nombre_empresa SET DEFAULT 'APEX'"); } catch(Exception $e) {}
    // Migración: observación del proveedor (qué suministra: vidrios, repuestos…)
    try { $pdo->exec("ALTER TABLE proveedores ADD COLUMN observacion TEXT NULL AFTER email"); } catch(Exception $e) {}

    // Desglose de ventas registradas antes de la tabla pagos_venta: un solo método = el de la venta; pago mixto = se
    // reconstruye con los ingresos de caja ("Venta #N — Método") y el saldo pendiente
    $sinDesglose = $pdo->query("SELECT v.* FROM ventas v WHERE NOT EXISTS (SELECT 1 FROM pagos_venta p WHERE p.id_venta=v.id)")->fetchAll(PDO::FETCH_ASSOC);
    $insPago = $pdo->prepare("INSERT INTO pagos_venta (id_venta,metodo,monto,entidad) VALUES (?,?,?,?)");
    $cajaVenta = $pdo->prepare("SELECT monto, descripcion FROM caja_chica WHERE referencia_origen=? AND tipo='Ingreso'");
    foreach ($sinDesglose as $v) {
        if ($v['estado'] !== 'Mixto') { $insPago->execute([$v['id'], $v['estado'], $v['total'], $v['entidad_financiera']]); continue; }
        $cajaVenta->execute(["Venta #{$v['id']}"]);
        $pagado = 0;
        foreach ($cajaVenta->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $met = preg_match('/ — (.+)$/u', (string)$c['descripcion'], $m) ? $m[1] : 'Efectivo';
            $insPago->execute([$v['id'], $met, $c['monto'], null]);
            $pagado += (float)$c['monto'];
        }
        $resto = round((float)$v['total'] - $pagado, 2);
        if ($resto > 0) $insPago->execute([$v['id'], $v['entidad_financiera'] ? 'Terceros' : 'Credito Interno', $resto, $v['entidad_financiera']]);
    }

    // Usuarios iniciales (solo en una instalación nueva): cambiar las contraseñas al entrar
    if ($pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn() == 0) {
        $usuarios_creados = true;
        $pdo->prepare("INSERT INTO usuarios (nombre,usuario,password,rol,debe_cambiar_clave) VALUES (?,?,?,?,1),(?,?,?,?,1)")->execute([
            'Administrador', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin',
            'Vendedor', 'vendedor', password_hash('vendedor123', PASSWORD_DEFAULT), 'vendedor',
        ]);
    }
    // Quien conserve la contraseña inicial (admin123 / vendedor123) deberá cambiarla al ingresar
    $marcar = $pdo->prepare("UPDATE usuarios SET debe_cambiar_clave=1 WHERE id=?");
    foreach ($pdo->query("SELECT id, usuario, password FROM usuarios WHERE usuario IN ('admin','vendedor')")->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (password_verify($u['usuario'] . '123', $u['password'])) $marcar->execute([$u['id']]);
    }
    // Default client
    if ($pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO clientes (nombre,documento,telefono,email) VALUES ('Cliente General (Mostrador)','000000000','N/A','N/A')");
    }
    // Configuración inicial de la empresa (se completa en Configuración)
    if ($pdo->query("SELECT COUNT(*) FROM configuracion")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO configuracion (id,nombre_empresa,mensaje_ticket) VALUES (1,'APEX','¡Gracias por su compra!')");
    }
    // Categorías iniciales (editables y eliminables desde Inventario); sin productos ni proveedores de ejemplo
    if ($usuarios_creados && $pdo->query("SELECT COUNT(*) FROM categorias")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO categorias (nombre) VALUES ('Celulares'),('Computadores'),('Tablets'),('Accesorios'),('Repuestos')");
    }

    if ($cli) { echo APP_NAME . ": base de datos " . DB_NAME . " lista." . ($usuarios_creados ? " Usuario inicial: admin / admin123 (cámbiala)." : '') . "\n"; exit; }
    setup_page('✅', $usuarios_creados ? APP_NAME . ' instalado' : 'Base de datos actualizada',
        $usuarios_creados
            ? "<p class='text-muted'>Base de datos creada y lista.<br>Usuario: <b>admin</b> / Clave: <b>admin123</b><br><b class='text-danger'>Cambia esta contraseña en Usuarios apenas ingreses.</b></p><a href='index.php' class='btn btn-dark px-4 py-2 mt-2'>Ir al inicio de sesión</a>"
            : "<p class='text-muted'>La estructura de la base de datos está al día. No se modificó ningún dato.</p><a href='dashboard.php' class='btn btn-dark px-4 py-2 mt-2'>Volver al sistema</a>");
} catch (PDOException $e) {
    error_log('Setup: ' . $e->getMessage());
    if ($cli) { echo "Error en setup: " . $e->getMessage() . "\n"; exit(1); }
    setup_page('⚠️', 'No se pudo instalar', "<p class='text-muted'>Revisa que MySQL esté encendido y los datos de acceso en <code>config/config.php</code>.</p><pre class='text-danger small text-start' style='white-space:pre-wrap'>" . htmlspecialchars($e->getMessage()) . "</pre>");
}
