# Historial de cambios

Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/); versiones según [SemVer](https://semver.org/lang/es/).

## [1.0.0] — 2026-10-06

Primera versión pública de **APEX by Ing. Antony Pérez**.

### Punto de venta y cartera
- Venta con carrito, productos por color, obsequios y descuentos; ticket térmico de 80 mm.
- Formas de pago: efectivo, transferencia, tarjeta, terceros (Addi, Sistecrédito u otra), crédito interno con autorización y pago mixto.
- Cartera con abonos por medio de pago e historial.

### Caja
- Sesiones de caja con apertura y cierre manual: la caja nunca se cierra sola.
- Vendedor y administrador pueden abrir y cerrar; gastos/ingresos manuales y ajustes solo el administrador.
- Arqueo con efectivo esperado, sobrante/faltante en vivo y reporte de cierre detallado descargable en PDF.
- Descuadres pendientes con fecha hasta cuadrarlos mediante ajustes totales o parciales; visibles en Dashboard, Caja, Reportes y PDF.

### Inventario y administración
- Categorías editables y eliminables (los productos quedan «Sin categoría»).
- Proveedores con «qué suministra»; módulo restringido al administrador.
- Reportes en Excel: ventas, inventario, cartera y descuadres.

### Seguridad y producción
- Cambio obligatorio de la contraseña inicial; mínimo 8 caracteres; cambio de contraseña propia.
- Bloqueo por intentos fallidos, sesiones seguras con expiración por inactividad, CSRF, consultas parametrizadas, escape contra XSS.
- Cabeceras CSP y de seguridad; bloqueo de carpetas internas, `.git` y archivos de configuración.
- Bloqueos transaccionales en caja, stock y abonos para usuarios simultáneos.
- Configuración por `config.local.php` o variables de entorno; registro de errores sin exponerlos.
- Instalación en Windows (XAMPP), Linux (Apache/Nginx), macOS y Docker; integración continua con GitHub Actions.
- Pruebas automáticas HTTP y de navegador; manuales de usuario, instalación, técnico y guía rápida.
