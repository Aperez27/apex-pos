#!/bin/bash
# Pruebas end-to-end de APEX vía HTTP (usa la carpeta donde está instalado el sistema)
RAIZ=$(cd "$(dirname "$0")/.." && pwd)
B=${APEX_URL:-http://localhost/$(basename "$RAIZ")}
LOG="$RAIZ/logs/php_errors.log"; LOG_INI=$( [ -f "$LOG" ] && wc -l < "$LOG" || echo 0 )
J=$(mktemp); JV=$(mktemp); JA=$(mktemp)
# Rutas configurables (por defecto XAMPP en Windows; en Linux/macOS usa mysql y php del PATH)
MYSQL_BIN=${MYSQL_BIN:-$( [ -x /c/xampp/mysql/bin/mysql.exe ] && echo /c/xampp/mysql/bin/mysql.exe || echo mysql )}
PHP_BIN=${PHP_BIN:-$( [ -x /c/xampp/php/php.exe ] && echo /c/xampp/php/php.exe || echo php )}
DB=${APEX_DB_NAME:-apex_db}
SQL() { $MYSQL_BIN -u${MYSQL_USER:-root} ${MYSQL_PASS:+-p$MYSQL_PASS} --default-character-set=utf8mb4 $DB -N -e "$1"; }
# Administrador temporal de pruebas (no se usan ni se cambian las cuentas reales)
ADM=zzadmin; ADM_PASS=ZzPrueba2026
SQL "DELETE FROM usuarios WHERE usuario='$ADM'; INSERT INTO usuarios (nombre,usuario,password,rol) VALUES ('ZZ Admin Pruebas','$ADM','$($PHP_BIN -r "echo password_hash('$ADM_PASS', PASSWORD_DEFAULT);")','admin')"
ADMIN_ID=$(SQL "SELECT id FROM usuarios WHERE usuario='$ADM'")
PASS=0; FAIL=0
ok()   { echo "  PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "  FAIL  $1"; FAIL=$((FAIL+1)); }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (esperado '$3', obtenido '$2')"; fi; }
tok()  { curl -s -b $1 -c $1 "$B/$2" | grep -o 'name="csrf_token" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//'; }
post() { curl -s -o /dev/null -w "%{http_code}" -b $1 -c $1 "$B/$2" --data-urlencode "csrf_token=$T" "${@:3}"; }
flash(){ curl -s -b $1 -c $1 "$B/$2" | grep -o 'alert alert-[a-z]* alert-dismiss[^>]*>[^<]*' | sed 's/.*>//' | head -1; }

echo "== Login"
check "login admin redirige" "$(curl -s -o /dev/null -w '%{http_code}' -c $J -b $J -d "usuario=$ADM&password=$ADM_PASS" $B/index.php)" 302
check "login incorrecto no entra" "$(curl -s -o /dev/null -w '%{http_code}' -c $JA -b $JA -d "usuario=$ADM&password=mala" $B/index.php)" 200

echo "== Páginas sin errores PHP (admin)"
for p in dashboard ventas abonos clientes proveedores productos averiados caja reportes usuarios backup configuracion; do
  out=$(curl -s -b $J -w '|%{http_code}' "$B/$p.php")
  code=${out##*|}
  if [ "$code" = 200 ] && ! echo "$out" | grep -qiE '<b>(warning|notice|fatal error|deprecated)</b>'; then ok "$p.php"; else bad "$p.php (HTTP $code o error PHP)"; fi
done

echo "== Marca y seguridad"
check "login con marca y copyright" "$(curl -s $B/index.php | grep -c 'APEX by Ing. Antony Pérez')" 1
check "panel con pie de copyright" "$(curl -s -b $J $B/dashboard.php | grep -c 'Todos los derechos reservados')" 1
check "config/ bloqueada" "$(curl -s -o /dev/null -w '%{http_code}' $B/config/config.php)" 403
check "includes/ bloqueada" "$(curl -s -o /dev/null -w '%{http_code}' $B/includes/header.php)" 403
check "tests/ bloqueada" "$(curl -s -o /dev/null -w '%{http_code}' $B/tests/e2e.sh)" 403
check "logs/ bloqueada" "$(curl -s -o /dev/null -w '%{http_code}' $B/logs/php_errors.log)" 403
for u in .git/config .env.example .gitignore Dockerfile docker-compose.yml LICENSE README.md deploy/crear_base_mysql.sql docker/entrypoint.sh .github/SECURITY.md config/config.local.example.php; do
  check "$u bloqueado" "$(curl -s -o /dev/null -w '%{http_code}' $B/$u)" 403
done
check "no expone versión de PHP" "$(curl -sI $B/index.php | grep -ci 'x-powered-by')" 0
check "setup bloqueado sin sesión" "$(curl -s -o /dev/null -w '%{http_code}' $B/setup.php)" 403
check "setup (actualizar) como admin" "$(curl -s -b $J $B/setup.php | grep -c 'Base de datos actualizada')" 1

echo "== Productos"
T=$(tok $J productos.php)
check "crear producto" "$(post $J productos.php -d guardar_producto=1 -d nombre=ZZPROD -d precio_compra=10 -d precio_venta=20 -d stock=5 -d id_categoria= -d id_proveedor=)" 302
PID=$(SQL "SELECT id FROM productos WHERE nombre='ZZPROD'")
check "producto en BD" "$([ -n "$PID" ] && echo si)" si
check "editar producto" "$(post $J productos.php -d editar_producto=1 -d id=$PID -d nombre=ZZPROD -d precio_compra=10 -d precio_venta=25 -d id_categoria= -d id_proveedor=)" 302
check "precio editado" "$(SQL "SELECT precio_venta FROM productos WHERE id=$PID")" 25.00
check "restock" "$(post $J productos.php -d restock=1 -d id=$PID -d cantidad_restock=5)" 302
check "stock=10" "$(SQL "SELECT stock FROM productos WHERE id=$PID")" 10
check "eliminar SIN token -> 403" "$(curl -s -o /dev/null -w '%{http_code}' -b $J -d eliminar=$PID $B/productos.php)" 403
check "eliminar por GET ya no hace nada" "$(curl -s -o /dev/null -w '%{http_code}' -b $J "$B/productos.php?eliminar=$PID"; SQL "SELECT COUNT(*) FROM productos WHERE id=$PID")" "2001"
T=$(tok $J productos.php)
check "crear producto 2" "$(post $J productos.php -d guardar_producto=1 -d nombre=ZZBORRAR -d precio_compra=1 -d precio_venta=2 -d stock=1 -d id_categoria= -d id_proveedor=)" 302
P2=$(SQL "SELECT id FROM productos WHERE nombre='ZZBORRAR'")
check "eliminar producto (POST+token)" "$(post $J productos.php -d eliminar=$P2)" 302
check "producto eliminado de BD" "$(SQL "SELECT COUNT(*) FROM productos WHERE id=$P2")" 0
check "mensaje flash" "$(flash $J productos.php)" "Producto eliminado."

echo "== Caja: apertura"
CAJA_PREVIA=$(SQL "SELECT IFNULL(MAX(id),0) FROM cajas WHERE estado='Abierta'")
if [ "$CAJA_PREVIA" = 0 ]; then
  T=$(tok $J ventas.php)
  post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=1 >/dev/null
  check "sin caja abierta no se puede vender" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Efectivo >/dev/null; flash $J ventas.php | grep -c 'caja está cerrada')" 1
  curl -s -o /dev/null -b $J "$B/ventas.php?eliminar=p$PID"
  check "abrir caja desde punto de venta" "$(post $J ventas.php -d abrir_caja=1 -d monto_inicial=100 -d obs_apertura=ZZCAJA)" 302
  check "no abre dos cajas a la vez" "$(post $J ventas.php -d abrir_caja=1 -d monto_inicial=1 >/dev/null; SQL "SELECT COUNT(*) FROM cajas WHERE estado='Abierta'")" 1
else
  echo "  (hay una caja abierta #$CAJA_PREVIA: se omiten las pruebas de apertura/cierre)"
fi
CAJA=$(SQL "SELECT MAX(id) FROM cajas WHERE estado='Abierta'")

echo "== Ventas"
T=$(tok $J ventas.php)
check "agregar al carrito" "$(post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=2)" 302
check "no permite más que el stock" "$(post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=50 >/dev/null; flash $J ventas.php | grep -c 'Solo')" 1
check "cobrar Efectivo" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Efectivo -d descuento=5)" 302
V1=$(SQL "SELECT MAX(id) FROM ventas")
check "total con descuento" "$(SQL "SELECT total FROM ventas WHERE id=$V1")" 45.00
check "stock descontado" "$(SQL "SELECT stock FROM productos WHERE id=$PID")" 8
check "ingreso en caja" "$(SQL "SELECT monto FROM caja_chica WHERE referencia_origen='Venta #$V1'")" 45.00
check "venta y movimiento ligados a la caja" "$(SQL "SELECT CONCAT(v.id_caja,'|',c.id_caja,'|',c.metodo) FROM ventas v JOIN caja_chica c ON c.referencia_origen=CONCAT('Venta #',v.id) WHERE v.id=$V1")" "$CAJA|$CAJA|Efectivo"
check "ticket abre" "$(curl -s -b $J "$B/ticket.php?id=$V1" | grep -c ZZPROD)" 1
T=$(tok $J ventas.php)
post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=1 >/dev/null
SQL "INSERT INTO clientes (nombre,documento) VALUES ('ZZ Cliente Credito','ZZCRED1')"; CCRED=$(SQL "SELECT id FROM clientes WHERE documento='ZZCRED1'")
ULT=$(SQL "SELECT IFNULL(MAX(id),0) FROM ventas")
check "forma de pago inválida (Fiado) rechazada" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=$CCRED -d estado=Fiado >/dev/null; SQL "SELECT IFNULL(MAX(id),0) FROM ventas")" $ULT
check "crédito interno sin quién autoriza rechazado" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=$CCRED -d 'estado=Credito Interno' >/dev/null; SQL "SELECT IFNULL(MAX(id),0) FROM ventas")" $ULT
check "crédito interno a Cliente General rechazado" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d 'estado=Credito Interno' -d id_autoriza=$ADMIN_ID >/dev/null; SQL "SELECT IFNULL(MAX(id),0) FROM ventas")" $ULT
check "terceros sin entidad rechazado" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Terceros >/dev/null; SQL "SELECT IFNULL(MAX(id),0) FROM ventas")" $ULT
check "terceros 'Otra' sin nombre rechazado" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Terceros -d entidad=Otra >/dev/null; SQL "SELECT IFNULL(MAX(id),0) FROM ventas")" $ULT
check "cobrar Crédito Interno" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=$CCRED -d 'estado=Credito Interno' -d id_autoriza=$ADMIN_ID -d descuento=0)" 302
V2=$(SQL "SELECT MAX(id) FROM ventas")
check "saldo pendiente crédito interno" "$(SQL "SELECT saldo_pendiente FROM ventas WHERE id=$V2")" 25.00
check "guarda quién autoriza" "$(SQL "SELECT id_autoriza FROM ventas WHERE id=$V2")" $ADMIN_ID
check "crédito interno no entra a caja" "$(SQL "SELECT COUNT(*) FROM caja_chica WHERE referencia_origen='Venta #$V2'")" 0
check "ticket muestra quién autorizó" "$(curl -s -b $J "$B/ticket.php?id=$V2" | grep -c 'Autorizó')" 1
post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=1 >/dev/null
check "mixto con suma incorrecta rechazado" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Mixto -d 'pagos[0][metodo]=Efectivo' -d 'pagos[0][monto]=5' >/dev/null; SQL "SELECT MAX(id) FROM ventas")" $V2
check "mixto correcto" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Mixto -d 'pagos[0][metodo]=Efectivo' -d 'pagos[0][monto]=10' -d 'pagos[1][metodo]=Tarjeta' -d 'pagos[1][monto]=15')" 302
V3=$(SQL "SELECT MAX(id) FROM ventas")
check "mixto: 2 ingresos en caja" "$(SQL "SELECT COUNT(*) FROM caja_chica WHERE referencia_origen='Venta #$V3'")" 2
check "mixto: desglose guardado" "$(SQL "SELECT GROUP_CONCAT(CONCAT(metodo,'=',monto) ORDER BY id) FROM pagos_venta WHERE id_venta=$V3")" "Efectivo=10.00,Tarjeta=15.00"
post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=1 >/dev/null
check "terceros con otra entidad" "$(post $J ventas.php -d procesar_venta=1 -d id_cliente=1 -d estado=Mixto -d 'pagos[0][metodo]=Efectivo' -d 'pagos[0][monto]=5' -d 'pagos[1][metodo]=Terceros' -d 'pagos[1][monto]=20' -d entidad=Otra -d 'entidad_otra=ZZ%20Banco%20F%C3%A1cil')" 302
V4=$(SQL "SELECT MAX(id) FROM ventas")
check "mixto con terceros: saldo solo de la parte terceros (y tildes OK)" "$(SQL "SELECT CONCAT(saldo_pendiente,'|',HEX(entidad_financiera)) FROM ventas WHERE id=$V4")" "20.00|5A5A2042616E636F2046C3A163696C"
check "producto con ventas NO se elimina" "$(post $J productos.php -d eliminar=$PID >/dev/null; SQL "SELECT COUNT(*) FROM productos WHERE id=$PID")" 1

echo "== Abonos"
T=$(tok $J abonos.php)
post $J abonos.php -d registrar_abono=1 -d id_venta=$V2 -d monto=100 -d metodo=Efectivo >/dev/null
check "abono mayor al saldo rechazado" "$(SQL "SELECT saldo_pendiente FROM ventas WHERE id=$V2")" 25.00
post $J abonos.php -d registrar_abono=1 -d id_venta=$V2 -d monto=10 -d observacion=ZZABONO >/dev/null
check "abono sin método rechazado" "$(SQL "SELECT saldo_pendiente FROM ventas WHERE id=$V2")" 25.00
post $J abonos.php -d registrar_abono=1 -d id_venta=$V2 -d monto=10 -d metodo=Transferencia -d observacion=ZZABONO >/dev/null
check "abono guarda el método" "$(SQL "SELECT metodo FROM abonos WHERE id_venta=$V2")" Transferencia
check "abono parcial" "$(SQL "SELECT saldo_pendiente FROM ventas WHERE id=$V2")" 15.00
# Dos abonos simultáneos por el saldo completo: solo uno debe registrarse
for i in 1 2; do post $J abonos.php -d registrar_abono=1 -d id_venta=$V2 -d monto=15 -d metodo=Efectivo >/dev/null & done; wait
check "abonos simultáneos no superan el saldo" "$(SQL "SELECT CONCAT(COUNT(*),'|',(SELECT saldo_pendiente FROM ventas WHERE id=$V2)) FROM abonos WHERE id_venta=$V2")" "2|0.00"
check "abono en caja" "$(SQL "SELECT monto FROM caja_chica WHERE referencia_origen='Abono #$V2' ORDER BY id LIMIT 1")" 10.00
check "abono ligado a la caja" "$(SQL "SELECT CONCAT(id_caja,'|',(SELECT metodo FROM caja_chica WHERE referencia_origen='Abono #$V2' ORDER BY id LIMIT 1)) FROM abonos WHERE id_venta=$V2 ORDER BY id LIMIT 1")" "$CAJA|Transferencia"
AJ=$(curl -s -b $J "$B/abonos.php?ajax_abonos=$V2")
check "historial AJAX solo devuelve la tabla" "$(echo "$AJ" | grep -c '<!DOCTYPE')$(echo "$AJ" | grep -c ZZABONO)" 01

echo "== Clientes"
T=$(tok $J clientes.php)
check "crear cliente" "$(post $J clientes.php -d guardar_cliente=1 -d nombre="ZZ O'Brien" -d documento=ZZ999 -d telefono= -d email=)" 302
check "documento duplicado rechazado" "$(post $J clientes.php -d guardar_cliente=1 -d nombre=ZZdup -d documento=ZZ999 -d telefono= -d email= >/dev/null; flash $J clientes.php)" "El documento ya está registrado."
CID=$(SQL "SELECT id FROM clientes WHERE documento='ZZ999'")
check "botón eliminar usa SweetAlert (data-confirm) y escapa comillas" "$(curl -s -b $J $B/clientes.php | grep -c 'data-confirm="¿Eliminar a «ZZ O&#039;Brien»?"')" 1
check "footer carga SweetAlert2" "$(curl -s -b $J $B/clientes.php | grep -c 'sweetalert2@11')" 1
check "ya no quedan confirm() nativos" "$(curl -s -b $J $B/clientes.php | grep -c 'return confirm(')" 0
check "eliminar cliente general bloqueado" "$(post $J clientes.php -d eliminar=1 >/dev/null; SQL "SELECT COUNT(*) FROM clientes WHERE id=1")" 1
check "eliminar cliente" "$(post $J clientes.php -d eliminar=$CID)" 302
check "cliente eliminado" "$(SQL "SELECT COUNT(*) FROM clientes WHERE id=$CID")" 0

echo "== Proveedores"
T=$(tok $J proveedores.php)
check "crear proveedor" "$(post $J proveedores.php -d guardar_proveedor=1 -d nombre=ZZPROV -d contacto= -d telefono= -d email= -d 'observacion=Vidrios templados')" 302
VID=$(SQL "SELECT id FROM proveedores WHERE nombre='ZZPROV'")
check "proveedor guarda observación" "$(SQL "SELECT observacion FROM proveedores WHERE id=$VID")" "Vidrios templados"
check "editar observación" "$(post $J proveedores.php -d editar_proveedor=1 -d id=$VID -d nombre=ZZPROV -d contacto= -d telefono= -d email= -d 'observacion=Repuestos' >/dev/null; SQL "SELECT observacion FROM proveedores WHERE id=$VID")" Repuestos
check "observación visible en la lista" "$(curl -s -b $J $B/proveedores.php | grep -c '>Repuestos<')" 1
check "eliminar proveedor" "$(post $J proveedores.php -d eliminar=$VID)" 302
check "proveedor eliminado" "$(SQL "SELECT COUNT(*) FROM proveedores WHERE id=$VID")" 0
SQL "INSERT INTO proveedores (nombre) VALUES ('ZZPROVCONPROD')"; VP2=$(SQL "SELECT id FROM proveedores WHERE nombre='ZZPROVCONPROD'"); SQL "UPDATE productos SET id_proveedor=$VP2 WHERE id=$PID"
check "proveedor con productos bloqueado" "$(post $J proveedores.php -d eliminar=$VP2 >/dev/null; SQL "SELECT COUNT(*) FROM proveedores WHERE id=$VP2")" 1

echo "== Usuarios"
T=$(tok $J usuarios.php)
check "contraseña de menos de 8 caracteres rechazada" "$(post $J usuarios.php -d crear_usuario=1 -d nombre=ZZ -d usuario=zzcorta -d password=1234567 -d rol=vendedor >/dev/null; SQL "SELECT COUNT(*) FROM usuarios WHERE usuario='zzcorta'")" 0
check "crear usuario" "$(post $J usuarios.php -d crear_usuario=1 -d nombre=ZZ -d usuario=zzuser -d password=secreto1 -d rol=superadmin)" 302
UID2=$(SQL "SELECT id FROM usuarios WHERE usuario='zzuser'")
check "rol inválido se guarda como vendedor" "$(SQL "SELECT rol FROM usuarios WHERE id=$UID2")" vendedor
check "cambiar contraseña" "$(post $J usuarios.php -d cambiar_pass=1 -d id_usuario=$UID2 -d nueva_password=otra12345)" 302
check "login con nueva contraseña" "$(curl -s -o /dev/null -w '%{http_code}' -c $JV -b $JV -d 'usuario=zzuser&password=otra12345' $B/index.php)" 302
echo "  -- como vendedor:"
check "vendedor: proveedores denegado" "$(curl -s -b $JV $B/proveedores.php | grep -c 'Acceso denegado')" 1
check "vendedor: menú sin proveedores" "$(curl -s -b $JV $B/ventas.php | grep -c 'proveedores.php')" 0
check "vendedor: ve el reporte de caja" "$(curl -s -o /dev/null -w '%{http_code}' -b $JV "$B/caja_reporte.php?id=$CAJA")" 200
VC=$(curl -s -b $JV $B/caja.php)
check "vendedor: caja con cierre pero sin egresos ni historial" "$(echo "$VC" | grep -c 'Cerrar caja y generar reporte')$(echo "$VC" | grep -c 'Registrar Egreso')$(echo "$VC" | grep -c 'Referencia/Ticket')" 100
TV=$(tok $JV caja.php)
check "vendedor: no puede registrar egresos" "$(curl -s -o /dev/null -b $JV --data-urlencode "csrf_token=$TV" -d movimiento=1 -d tipo=Egreso -d monto=1 -d descripcion=ZZVEND $B/caja.php; SQL "SELECT COUNT(*) FROM caja_chica WHERE descripcion='ZZVEND'")" 0
check "vendedor: productos denegado" "$(curl -s -b $JV $B/productos.php | grep -c 'Acceso denegado')" 1
check "vendedor: exportar ventas redirige" "$(curl -s -o /dev/null -w '%{http_code}' -b $JV $B/exportar.php)" 302
check "vendedor: backup redirige" "$(curl -s -o /dev/null -w '%{http_code}' -b $JV $B/backup.php)" 302
check "vendedor: ventas OK" "$(curl -s -o /dev/null -w '%{http_code}' -b $JV $B/ventas.php)" 200
check "no puede eliminarse a sí mismo" "$(post $J usuarios.php -d eliminar=$ADMIN_ID >/dev/null; SQL "SELECT COUNT(*) FROM usuarios WHERE id=$ADMIN_ID")" 1
check "eliminar usuario" "$(post $J usuarios.php -d eliminar=$UID2)" 302
check "usuario eliminado" "$(SQL "SELECT COUNT(*) FROM usuarios WHERE id=$UID2")" 0

echo "== Caja / Averiados / Config"
T=$(tok $J caja.php)
post $J caja.php -d movimiento=1 -d tipo=Egreso -d monto=3 -d descripcion=ZZGASTO >/dev/null
check "egreso manual" "$(SQL "SELECT CONCAT(COUNT(*),'|',IFNULL(MAX(id_caja),''),'|',IFNULL(MAX(metodo),'')) FROM caja_chica WHERE descripcion='ZZGASTO'")" "1|$CAJA|Efectivo"
post $J caja.php -d movimiento=1 -d tipo=Ingreso -d monto=7 -d metodo=Transferencia -d descripcion=ZZINGRESO >/dev/null
check "ingreso manual por cuenta" "$(SQL "SELECT metodo FROM caja_chica WHERE descripcion='ZZINGRESO'")" Transferencia
check "reporte parcial abre" "$(curl -s -b $J "$B/caja_reporte.php?id=$CAJA" | grep -c 'Reporte parcial de caja')" 1

echo "== Descuadres de caja"
SQL "INSERT INTO cajas (estado,fecha_apertura,id_usuario_apertura,monto_inicial,fecha_cierre,id_usuario_cierre,efectivo_esperado,efectivo_contado,obs_cierre)
     VALUES ('Cerrada',NOW()-INTERVAL 1 DAY,$ADMIN_ID,0,NOW()-INTERVAL 1 DAY,$ADMIN_ID,100,90,'ZZDESC')"
DQ=$(SQL "SELECT id FROM cajas WHERE obs_cierre='ZZDESC'")
check "caja muestra el descuadre pendiente" "$(curl -s -b $J $B/caja.php | grep -q "data-id=\"$DQ\" data-tipo=\"Faltante\" data-pendiente=\"10\"" && echo si)" si
check "reportes muestra el descuadre" "$(curl -s -b $J $B/reportes.php | grep -q "caja_reporte.php?id=$DQ\"" && echo si)" si
check "reporte del cierre: pendiente" "$(curl -s -b $J "$B/caja_reporte.php?id=$DQ" | grep -c 'PENDIENTE \$10.00')" 1
T=$(tok $J caja.php)
check "cuadrar más de lo pendiente rechazado" "$(post $J caja.php -d cuadrar_descuadre=1 -d id_descuadre=$DQ -d monto=20 -d metodo=Transferencia >/dev/null; SQL "SELECT COUNT(*) FROM caja_chica WHERE ajusta_caja=$DQ")" 0
post $J caja.php -d cuadrar_descuadre=1 -d id_descuadre=$DQ -d monto=4 -d metodo=Transferencia -d descripcion=ZZREPONE >/dev/null
check "ajuste parcial ligado a ambas cajas" "$(SQL "SELECT CONCAT(tipo,'|',monto,'|',id_caja,'|',referencia_origen) FROM caja_chica WHERE ajusta_caja=$DQ")" "Ingreso|4.00|$CAJA|Ajuste caja #$DQ"
check "sigue pendiente el resto" "$(curl -s -b $J $B/caja.php | grep -q "data-id=\"$DQ\" data-tipo=\"Faltante\" data-pendiente=\"6\"" && echo si)" si
post $J caja.php -d cuadrar_descuadre=1 -d id_descuadre=$DQ -d monto=6 -d metodo=Transferencia >/dev/null
check "descuadre cuadrado sale de pendientes" "$(curl -s -b $J $B/caja.php | grep -c "data-id=\"$DQ\" data-tipo")" 0
check "no se puede ajustar un descuadre ya cuadrado" "$(post $J caja.php -d cuadrar_descuadre=1 -d id_descuadre=$DQ -d monto=1 -d metodo=Transferencia >/dev/null; SQL "SELECT SUM(monto) FROM caja_chica WHERE ajusta_caja=$DQ")" 10.00
check "reporte del cierre: cuadrado con ajustes" "$(curl -s -b $J "$B/caja_reporte.php?id=$DQ" | grep -c 'ZZREPONE\|CUADRADO ')" 2
check "reportes (todos) lo muestra cuadrado" "$(curl -s -b $J "$B/reportes.php?descuadres=todos" | grep -B1 "caja_reporte.php?id=$DQ\"" | grep -c 'badge-success">Cuadrado')" 1
check "ajuste aparece en el reporte de la caja donde se registró" "$(curl -s -b $J "$B/caja_reporte.php?id=$CAJA" | grep -c 'ZZREPONE')" 1
check "exportar descuadres Excel" "$(curl -s -o /dev/null -w '%{content_type}' -b $J "$B/exportar_descuadres.php")" "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
if [ "$CAJA_PREVIA" = 0 ]; then
  # base 100 + ventas efectivo (45 + 10 + 5) − egreso 3 = 157
  check "efectivo esperado en pantalla" "$(curl -s -b $J $B/caja.php | grep -o 'data-valor="[0-9.]*"')" 'data-valor="157"'
  T=$(tok $J caja.php)
  check "cierre sin efectivo contado rechazado" "$(post $J caja.php -d cerrar_caja=1 -d id_caja=$CAJA -d efectivo_contado= >/dev/null; SQL "SELECT estado FROM cajas WHERE id=$CAJA")" Abierta
  check "cerrar caja redirige al reporte" "$(curl -s -o /dev/null -w '%{redirect_url}' -b $J --data-urlencode "csrf_token=$T" -d cerrar_caja=1 -d id_caja=$CAJA -d efectivo_contado=150 -d obs_cierre=ZZCIERRE $B/caja.php | grep -o 'caja_reporte.php?id=[0-9]*')" "caja_reporte.php?id=$CAJA"
  check "caja cerrada con arqueo" "$(SQL "SELECT CONCAT(estado,'|',efectivo_esperado,'|',efectivo_contado) FROM cajas WHERE id=$CAJA")" "Cerrada|157.00|150.00"
  RP=$(curl -s -b $J "$B/caja_reporte.php?id=$CAJA")
  check "reporte: faltante" "$(echo "$RP" | grep -c 'Faltante \$7.00')" 1
  check "reporte: detalle con cliente y producto" "$(echo "$RP" | grep -q 'ZZ Cliente Credito' && echo "$RP" | grep -q '1 × ZZPROD' && echo si)" si
  check "reporte: botón PDF" "$(echo "$RP" | grep -q 'html2pdf.bundle.min.js' && echo si)" si
  check "con caja cerrada no se registran egresos" "$(post $J caja.php -d movimiento=1 -d tipo=Egreso -d monto=1 -d metodo=Efectivo -d descripcion=ZZNO >/dev/null; SQL "SELECT COUNT(*) FROM caja_chica WHERE descripcion='ZZNO'")" 0
  check "listado de cierres" "$(curl -s -b $J $B/caja.php | grep -q "caja_reporte.php?id=$CAJA\" target=\"_blank\" class=\"btn btn-xs" && echo si)" si
fi

echo "== Categorías"
T=$(tok $J productos.php)
post $J productos.php -d guardar_categoria=1 -d nombre_categoria=ZZCAT >/dev/null
CAT=$(SQL "SELECT id FROM categorias WHERE nombre='ZZCAT'")
check "crear categoría" "$([ -n "$CAT" ] && echo si)" si
check "renombrar categoría" "$(post $J productos.php -d editar_categoria=1 -d id_categoria=$CAT -d nombre_categoria=ZZCAT2 >/dev/null; SQL "SELECT nombre FROM categorias WHERE id=$CAT")" ZZCAT2
SQL "UPDATE productos SET id_categoria=$CAT WHERE id=$PID"
check "eliminar categoría" "$(post $J productos.php -d eliminar_categoria=$CAT)" 302
check "categoría eliminada y producto conservado sin categoría" "$(SQL "SELECT CONCAT((SELECT COUNT(*) FROM categorias WHERE id=$CAT),'|',IFNULL(id_categoria,'NULL')) FROM productos WHERE id=$PID")" "0|NULL"
T=$(tok $J caja.php)
T=$(tok $J averiados.php)
post $J averiados.php -d registrar=1 -d id_producto=$PID -d tipo=Averiado -d cantidad=1 -d motivo=ZZROTO >/dev/null
check "averiado descuenta stock" "$(SQL "SELECT stock FROM productos WHERE id=$PID")" 4
check "averiado mayor al stock rechazado" "$(post $J averiados.php -d registrar=1 -d id_producto=$PID -d tipo=Averiado -d cantidad=999 -d motivo=ZZ >/dev/null; SQL "SELECT stock FROM productos WHERE id=$PID")" 4

echo "== Contraseña inicial obligatoria"
SQL "DELETE FROM usuarios WHERE usuario='zzforzado'; INSERT INTO usuarios (nombre,usuario,password,rol,debe_cambiar_clave) VALUES ('ZZ Forzado','zzforzado','$($PHP_BIN -r "echo password_hash('zzforzado123', PASSWORD_DEFAULT);")','vendedor',1)"
JF=$(mktemp); curl -s -o /dev/null -c $JF -b $JF -d 'usuario=zzforzado&password=zzforzado123' $B/index.php
check "con contraseña inicial redirige a cambiarla" "$(curl -s -o /dev/null -w '%{redirect_url}' -b $JF $B/ventas.php | grep -c 'cambiar_clave.php')" 1
T=$(tok $JF cambiar_clave.php)
check "no acepta repetir la contraseña inicial" "$(post $JF cambiar_clave.php -d cambiar=1 -d actual=zzforzado123 -d nueva=zzforzado123 -d repite=zzforzado123 >/dev/null; SQL "SELECT debe_cambiar_clave FROM usuarios WHERE usuario='zzforzado'")" 1
check "no acepta contraseña actual errada" "$(post $JF cambiar_clave.php -d cambiar=1 -d actual=mala -d nueva=Nueva2026x -d repite=Nueva2026x >/dev/null; SQL "SELECT debe_cambiar_clave FROM usuarios WHERE usuario='zzforzado'")" 1
check "cambia la contraseña" "$(post $JF cambiar_clave.php -d cambiar=1 -d actual=zzforzado123 -d nueva=Nueva2026x -d repite=Nueva2026x)" 302
check "ya puede usar el sistema" "$(curl -s -o /dev/null -w '%{http_code}' -b $JF $B/ventas.php)" 200
rm -f $JF

echo "== Backup / Exportar / Seguridad"
check "cabecera CSP presente" "$(curl -sI $B/index.php | grep -c "Content-Security-Policy: default-src 'self'")" 1
check "mismo mensaje con usuario inexistente" "$(curl -s -c $JA -b $JA -d 'usuario=zznoexiste&password=x' $B/index.php | grep -c 'Usuario o contraseña incorrectos')" 1
SQL "DELETE FROM login_intentos WHERE usuario IN ('zznoexiste')"
T=$(tok $J ventas.php)
check "venta a cliente inexistente rechazada" "$(post $J ventas.php -d agregar=1 -d id_producto=$PID -d cantidad=1 >/dev/null; ULT=$(SQL "SELECT MAX(id) FROM ventas"); post $J ventas.php -d procesar_venta=1 -d id_cliente=999999 -d estado=Efectivo >/dev/null; curl -s -o /dev/null -b $J "$B/ventas.php?eliminar=p$PID"; [ "$(SQL "SELECT MAX(id) FROM ventas")" = "$ULT" ] && echo si)" si
T=$(tok $J productos.php)
post $J productos.php -d guardar_producto=1 -d nombre=ZZNEG -d precio_compra=-50 -d precio_venta=10 -d stock=1 -d id_categoria=999999 -d id_proveedor=999999 >/dev/null
check "costo negativo y categoría/proveedor inexistentes se corrigen" "$(SQL "SELECT CONCAT(precio_compra,'|',IFNULL(id_categoria,'NULL'),'|',IFNULL(id_proveedor,'NULL')) FROM productos WHERE nombre='ZZNEG'")" "0.00|NULL|NULL"
SQL "DELETE FROM productos WHERE nombre='ZZNEG'"
check "sin sesión, páginas internas redirigen al login" "$(for p in dashboard ventas caja caja_reporte abonos reportes exportar_descuadres; do curl -s -o /dev/null -w '%{http_code}' $B/$p.php; done)" "302302302302302302302"
T=$(tok $J backup.php)
check "backup accesible para admin" "$(curl -s -o /dev/null -w '%{http_code}' -b $J $B/backup.php)" 200
check "backup descarga SQL" "$(curl -s -b $J --data-urlencode "csrf_token=$T" -d descargar=1 $B/backup.php | grep -c 'CREATE TABLE `productos`')" 1
XL="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
check "exportar ventas Excel" "$(curl -s -o /dev/null -w '%{content_type}' -b $J "$B/exportar.php")" $XL
check "exportar inventario Excel" "$(curl -s -o /dev/null -w '%{content_type}' -b $J "$B/exportar_inventario.php")" $XL
check "exportar cartera Excel" "$(curl -s -o /dev/null -w '%{content_type}' -b $J "$B/exportar_creditos.php")" $XL
check "ticket sin login redirige" "$(curl -s -o /dev/null -w '%{http_code}' "$B/ticket.php?id=$V1")" 302
check "logout" "$(curl -s -o /dev/null -w '%{http_code}' -b $JV -c $JV $B/logout.php)" 302
JB=$(mktemp)
for i in 1 2 3 4 5; do curl -s -o /dev/null -c $JB -b $JB -d 'usuario=zzbloqueo&password=mala' $B/index.php; done
check "bloqueo tras 5 intentos fallidos" "$(curl -s -c $JB -b $JB -d "usuario=$ADM&password=$ADM_PASS" $B/index.php | grep -c 'Demasiados intentos')" 1
SQL "DELETE FROM login_intentos WHERE usuario IN ('zzbloqueo','$ADM')"
check "sin errores PHP nuevos en el log" "$( [ -f "$LOG" ] && tail -n +$((LOG_INI+1)) "$LOG" | wc -l || echo 0)" 0
echo "== Limpieza de datos de prueba"
SQL "DELETE FROM caja_chica WHERE referencia_origen IN ('Venta #$V1','Venta #$V2','Venta #$V3','Abono #$V2') OR descripcion='ZZGASTO';
     DELETE FROM caja_chica WHERE referencia_origen='Venta #$V4';
     DELETE FROM ventas WHERE id IN ($V1,$V2,$V3,$V4);
     DELETE FROM clientes WHERE documento='ZZCRED1';
     DELETE FROM inventario_movimientos WHERE id_producto=$PID;
     DELETE FROM productos WHERE id=$PID;
     DELETE FROM proveedores WHERE nombre='ZZPROVCONPROD';
     DELETE FROM caja_chica WHERE descripcion='ZZINGRESO';
     DELETE FROM caja_chica WHERE ajusta_caja=$DQ;
     DELETE FROM cajas WHERE id=$DQ;"
[ "$CAJA_PREVIA" = 0 ] && SQL "DELETE FROM cajas WHERE id=$CAJA"
SQL "DELETE FROM login_intentos WHERE usuario LIKE 'zz%'; DELETE FROM usuarios WHERE usuario IN ('$ADM','zzforzado')"
rm -f $J $JV $JA $JB
echo; echo "RESULTADO: $PASS pasaron, $FAIL fallaron"
