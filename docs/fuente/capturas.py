"""Genera las capturas de pantalla de los manuales de APEX.

Se ejecuta contra una COPIA de demostración del sistema (base apex_demo), nunca contra la base real:
    python docs/fuente/capturas.py http://localhost/apex_demo_manual
Recorre un escenario de tres días (cajas, ventas con todas las formas de pago, abonos, un descuadre y
su ajuste) y guarda las imágenes en docs/fuente/img/.
"""
import os, shutil, subprocess, sys
from playwright.sync_api import sync_playwright

B = sys.argv[1] if len(sys.argv) > 1 else "http://localhost/apex_demo_manual"
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "img")
MYSQL = os.environ.get("MYSQL_BIN") or (r"C:\xampp\mysql\bin\mysql.exe" if os.name == "nt" else shutil.which("mysql"))
NAVEGADOR = os.environ.get("APEX_BROWSER", "msedge" if os.name == "nt" else "chromium")
CLAVE = "Demo2026!"
os.makedirs(OUT, exist_ok=True)


def sql(q):
    r = subprocess.run([MYSQL, "-uroot", "--default-character-set=utf8mb4", "apex_demo", "-N", "-e", q],
                       capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr)
    return r.stdout.strip()


def shot(target, nombre, full_page=False):
    ruta = os.path.join(OUT, nombre + ".jpg")
    if full_page:
        # Página completa: se agranda la ventana al alto del contenido para que el menú lateral fijo llegue abajo
        size = target.viewport_size
        target.evaluate("window.scrollTo(0, 0)")
        alto = target.evaluate("document.documentElement.scrollHeight")
        target.set_viewport_size({"width": size["width"], "height": max(alto, size["height"])})
        target.wait_for_timeout(300)
        target.screenshot(path=ruta, type="jpeg", quality=88)
        target.set_viewport_size(size)
    else:
        if hasattr(target, "evaluate") and not hasattr(target, "goto"):
            target.evaluate("e => e.ownerDocument.querySelectorAll('.topbar, .toolbar').forEach(t => t.style.visibility = 'hidden')")
        target.screenshot(path=ruta, type="jpeg", quality=88)
        if hasattr(target, "evaluate") and not hasattr(target, "goto"):
            target.evaluate("e => e.ownerDocument.querySelectorAll('.topbar, .toolbar').forEach(t => t.style.visibility = '')")
    print("  img", nombre)


def ir(pg, ruta):
    pg.goto(f"{B}/{ruta}")
    pg.wait_for_load_state("networkidle")
    pg.wait_for_timeout(250)


def esperar_sin_toast(pg):
    pg.wait_for_function("!document.querySelector('.swal2-container')", timeout=8000)


def dinero(scope, nombre, valor):
    scope.locator(f"input[name='{nombre}'] + input.money-input").fill(str(valor))


def login(ctx, usuario):
    pg = ctx.new_page()
    ir(pg, "index.php")
    pg.fill("input[name=usuario]", usuario)
    pg.fill("input[name=password]", CLAVE)
    pg.click("button[type=submit]")
    pg.wait_for_url("**/dashboard.php")
    return pg


def agregar(pg, pid, cant=1, obsequio=False):
    ir(pg, "ventas.php")
    pg.select_option("#selectProd", str(pid))
    pg.fill("input[name=cantidad]", str(cant))
    if obsequio:
        pg.check("#chkObsequio")
    pg.click("button[name=agregar]")
    pg.wait_for_load_state("networkidle")


def preparar_pago(pg, cliente, estado, descuento=0, entidad=None, referencia="", autoriza=None, pagos=None, obs=""):
    ir(pg, "ventas.php")
    pg.select_option("#selCliente", str(cliente))
    pg.select_option("#estadoVenta", estado)
    if descuento:
        dinero(pg, "descuento", descuento)
    if entidad:
        pg.select_option("#selEntidad", entidad)
        pg.fill("input[name=referencia]", referencia)
    if autoriza:
        pg.select_option("#selAutoriza", str(autoriza))
    if pagos:
        for i, (met, monto) in enumerate(pagos):
            if i > 0:
                pg.click("button:has-text('+ Agregar método')")
            pg.select_option(f"select[name='pagos[{i}][metodo]']", met)
            dinero(pg, f"pagos[{i}][monto]", monto)
    if obs:
        pg.fill("input[name=observacion]", obs)


def cobrar(pg):
    pg.click("button[name=procesar_venta]")
    pg.wait_for_load_state("networkidle")
    pg.wait_for_selector(".swal2-popup")


def vender(pg, items, cliente=1, estado="Efectivo", **kw):
    for it in items:
        agregar(pg, *it)
    preparar_pago(pg, cliente, estado, **kw)
    cobrar(pg)


def abrir_caja(pg, base, obs=""):
    ir(pg, "caja.php")
    dinero(pg, "monto_inicial", base)
    pg.fill("input[name=obs_apertura]", obs)
    pg.click("button[name=abrir_caja]")
    pg.wait_for_load_state("networkidle")


def cerrar_caja(pg, diferencia=0, obs=""):
    ir(pg, "caja.php")
    esperado = float(pg.get_attribute("#esperado", "data-valor"))
    dinero(pg, "efectivo_contado", int(esperado + diferencia))
    pg.fill("input[name=obs_cierre]", obs)
    pg.click("#formCierre button[type=submit]")
    pg.click(".swal2-confirm")
    pg.wait_for_url("**/caja_reporte.php*")
    pg.wait_for_load_state("networkidle")


def mover_caja(id_caja, dias, apertura, cierre):
    """Lleva una caja (y todo lo que contiene) a un día anterior con horas realistas."""
    d = f"CURDATE() - INTERVAL {dias} DAY"
    sql(f"""
        UPDATE cajas SET fecha_apertura=TIMESTAMP({d},'{apertura}'), fecha_cierre=TIMESTAMP({d},'{cierre}') WHERE id={id_caja};
        SET @n := 0;
        UPDATE ventas SET fecha=TIMESTAMP({d}, SEC_TO_TIME(33300 + (@n := @n + 1) * 6100)) WHERE id_caja={id_caja} ORDER BY id;
        UPDATE caja_chica c JOIN ventas v ON c.referencia_origen=CONCAT('Venta #',v.id) SET c.fecha=v.fecha WHERE v.id_caja={id_caja};
        UPDATE caja_chica SET fecha=TIMESTAMP({d},'17:40:00') WHERE id_caja={id_caja} AND referencia_origen NOT LIKE 'Venta%';
        UPDATE abonos SET fecha=TIMESTAMP({d},'16:20:00') WHERE id_caja={id_caja};
    """)


with sync_playwright() as p:
    br = p.chromium.launch(channel=None if NAVEGADOR == "chromium" else NAVEGADOR, headless=True)
    ctx = br.new_context(viewport={"width": 1366, "height": 860}, device_scale_factor=1.25, locale="es-CO")

    print("== Inicio de sesión")
    pg = ctx.new_page()
    ir(pg, "index.php")
    shot(pg, "login")
    pg.fill("input[name=usuario]", "laura"); pg.fill("input[name=password]", "otra")
    pg.click("button[type=submit]"); pg.wait_for_load_state("networkidle")
    shot(pg.locator(".login-card"), "login_error")
    sql("DELETE FROM login_intentos")
    pg.close()

    print("== Día 1 (hace 2 días): caja cuadrada")
    sql("UPDATE usuarios SET debe_cambiar_clave=1 WHERE usuario='laura'")
    lau = ctx.new_page()
    ir(lau, "index.php")
    lau.fill("input[name=usuario]", "laura"); lau.fill("input[name=password]", CLAVE)
    lau.click("button[type=submit]"); lau.wait_for_url("**/cambiar_clave.php")
    lau.wait_for_load_state("networkidle")
    shot(lau.locator(".wrap"), "cambiar_clave")
    lau.fill("input[name=actual]", CLAVE); lau.fill("input[name=nueva]", CLAVE + "x"); lau.fill("input[name=repite]", CLAVE + "x")
    lau.click("button[name=cambiar]"); lau.wait_for_url("**/dashboard.php")
    abrir_caja(lau, 200000, "Apertura de jornada")
    vender(lau, [(3, 1)], cliente=2, estado="Efectivo")
    vender(lau, [(11, 1), (12, 1)], estado="Transferencia")
    cerrar_caja(lau, 0)
    mover_caja(1, 2, "08:04:00", "19:10:00")

    print("== Día 2 (ayer): caja con faltante")
    abrir_caja(lau, 200000, "Apertura de jornada")
    vender(lau, [(5, 1)], cliente=2, estado="Terceros", entidad="Addi", referencia="AD-552190")
    vender(lau, [(9, 1), (8, 1)], cliente=5, estado="Efectivo")
    vender(lau, [(7, 1)], cliente=4, estado="Tarjeta")
    cerrar_caja(lau, -20000, "Faltaron $20.000 en efectivo; se revisa con la vendedora")
    mover_caja(2, 1, "08:01:00", "19:22:00")

    print("== Hoy: caja cerrada (vista del vendedor)")
    ir(lau, "dashboard.php")
    shot(lau, "dashboard_vendedor")
    shot(lau.locator(".sidebar"), "menu_vendedor")
    ir(lau, "ventas.php")
    shot(lau, "ventas_caja_cerrada")
    ir(lau, "caja.php")
    shot(lau, "caja_cerrada", full_page=True)

    print("== Hoy: apertura y ventas")
    abrir_caja(lau, 150000, "Apertura de jornada")
    sql("UPDATE cajas SET fecha_apertura=TIMESTAMP(CURDATE(),'08:05:00') WHERE id=3")
    # Venta 1: con obsequio y descuento, en efectivo
    for it in [(1, 1), (10, 1), (8, 1, True)]:
        agregar(lau, *it)
    preparar_pago(lau, 5, "Efectivo", descuento=49000, obs="")
    shot(lau, "ventas_carrito", full_page=True)
    cobrar(lau)
    lau.wait_for_timeout(500)
    shot(lau.locator(".swal2-popup"), "venta_exito")
    v1 = sql("SELECT MAX(id) FROM ventas")
    ir(lau, f"ticket.php?id={v1}")
    shot(lau.locator("body"), "ticket")
    # Venta 2: pago mixto
    agregar(lau, 6, 1)
    preparar_pago(lau, 4, "Mixto", pagos=[("Efectivo", 1000000), ("Transferencia", 3999000)])
    shot(lau.locator("#formVenta"), "pago_mixto")
    cobrar(lau)
    # Venta 3: crédito interno
    agregar(lau, 4, 1)
    preparar_pago(lau, 3, "Credito Interno", autoriza=1)
    shot(lau.locator("#formVenta"), "pago_credito")
    cobrar(lau)
    # Venta 4: terceros
    agregar(lau, 13, 1)
    preparar_pago(lau, 6, "Terceros", entidad="Sistecrédito", referencia="SC-88231")
    shot(lau.locator("#formVenta"), "pago_terceros")
    cobrar(lau)
    # Venta 5: tarjeta
    vender(lau, [(11, 1)], estado="Tarjeta")

    print("== Cartera")
    v3 = sql("SELECT id FROM ventas WHERE estado='Credito Interno' ORDER BY id DESC LIMIT 1")
    ir(lau, "abonos.php")
    lau.select_option("select[name=id_venta]", v3)
    dinero(lau, "monto", 600000)
    lau.click("label:has-text('Efectivo')")
    lau.fill("input[name=observacion]", "Primer abono")
    shot(lau, "abonos", full_page=True)
    lau.click("button[name=registrar_abono]"); lau.wait_for_load_state("networkidle")
    ir(lau, "abonos.php")
    lau.locator("button[data-bs-target='#modalHistorial']").first.click()
    lau.wait_for_selector("#modalHistorial.show"); lau.wait_for_timeout(800)
    shot(lau.locator("#modalHistorial .modal-content"), "abonos_historial")
    lau.close()

    print("== Administrador")
    actx = br.new_context(viewport={"width": 1366, "height": 860}, device_scale_factor=1.25, locale="es-CO")
    adm = login(actx, "admin")
    # Gasto del día y ajuste parcial del faltante de ayer
    ir(adm, "caja.php")
    dinero(adm.locator("form:has(input[name=tipo][value=Egreso])"), "monto", 15000)
    adm.fill("form:has(input[name=tipo][value=Egreso]) textarea[name=descripcion]", "Pasajes de mensajería")
    adm.click("form:has(input[name=tipo][value=Egreso]) button[name=movimiento]")
    adm.wait_for_load_state("networkidle")
    ir(adm, "caja.php")
    adm.locator("[data-bs-target='#modalCuadrar']").first.click()
    adm.wait_for_selector("#modalCuadrar.show")
    dinero(adm.locator("#modalCuadrar"), "monto", 10000)
    adm.fill("#modalCuadrar input[name=descripcion]", "Abono de la vendedora")
    adm.wait_for_timeout(400)
    shot(adm.locator("#modalCuadrar .modal-content"), "cuadrar_modal")
    adm.click("#modalCuadrar button[name=cuadrar_descuadre]"); adm.wait_for_load_state("networkidle")

    ir(adm, "caja.php")
    esperado = float(adm.get_attribute("#esperado", "data-valor"))
    dinero(adm, "efectivo_contado", int(esperado) - 5000)
    adm.wait_for_timeout(300)
    shot(adm, "caja_abierta", full_page=True)
    shot(adm.locator(".card:has(#formCierre)"), "caja_arqueo")
    shot(adm.locator(".card:has(.fa-triangle-exclamation)").first, "descuadres_pendientes")
    shot(adm.locator(".card:has(h6:has-text('Cierres de caja'))"), "cierres")
    adm.click("#formCierre button[type=submit]")
    adm.wait_for_selector(".swal2-popup"); adm.wait_for_timeout(400)
    shot(adm.locator(".swal2-popup"), "confirmar_cierre")
    adm.click(".swal2-cancel")
    ir(adm, "caja.php")
    adm.click("button[data-bs-target='#ayudaCaja']"); adm.wait_for_timeout(500)
    shot(adm.locator("#ayudaCaja"), "caja_ayuda")

    print("== Reporte de cierre")
    ir(adm, "caja_reporte.php?id=2")
    shot(adm.locator("#reporte"), "reporte_cierre_completo")

    print("== Inventario y demás módulos")
    ir(adm, "averiados.php")
    adm.select_option("select[name=id_producto]", "8")
    adm.select_option("select[name=tipo]", "Averiado")
    adm.fill("textarea[name=motivo]", "Se rompió al instalarlo")
    adm.click("button[name=registrar]"); adm.wait_for_load_state("networkidle")
    ir(adm, "averiados.php")
    shot(adm, "averiados", full_page=True)

    ir(adm, "dashboard.php")
    shot(adm, "dashboard_admin", full_page=True)
    shot(adm.locator(".sidebar"), "menu_admin")
    ir(adm, "productos.php")
    shot(adm, "productos", full_page=True)
    shot(adm.locator(".col-lg-3 .card"), "categorias")
    adm.click("button[data-bs-target='#modalAgregar']")
    adm.wait_for_selector("#modalAgregar.show")
    adm.fill("#modalAgregar input[name=nombre]", "iPhone 16 128GB")
    adm.select_option("#modalAgregar select[name=id_categoria]", "1")
    adm.select_option("#modalAgregar select[name=id_proveedor]", "1")
    dinero(adm.locator("#modalAgregar"), "precio_compra", 3600000)
    dinero(adm.locator("#modalAgregar"), "precio_venta", 4199000)
    adm.fill("#modalAgregar input[name='color[]']", "Negro")
    adm.fill("#modalAgregar input[name='stock[]']", "3")
    adm.click("#modalAgregar button:has-text('Agregar color')")
    adm.locator("#modalAgregar input[name='color[]']").nth(1).fill("Azul")
    adm.locator("#modalAgregar input[name='stock[]']").nth(1).fill("2")
    adm.wait_for_timeout(400)
    shot(adm.locator("#modalAgregar .modal-content"), "producto_nuevo")
    ir(adm, "productos.php")
    adm.locator("[data-bs-target='#modalCategoria']").first.click()
    adm.wait_for_selector("#modalCategoria.show"); adm.wait_for_timeout(400)
    shot(adm.locator("#modalCategoria .modal-content"), "categoria_renombrar")
    ir(adm, "productos.php")
    adm.locator(".col-lg-3 form[data-confirm] button").last.click()
    adm.wait_for_selector(".swal2-popup"); adm.wait_for_timeout(400)
    shot(adm.locator(".swal2-popup"), "confirmar_eliminar")
    adm.click(".swal2-cancel")

    ir(adm, "proveedores.php")
    shot(adm, "proveedores", full_page=True)
    adm.locator("button[data-bs-target='#modalEditar']").nth(1).click()
    adm.wait_for_selector("#modalEditar.show"); adm.wait_for_timeout(400)
    shot(adm.locator("#modalEditar .modal-content"), "proveedor_editar")

    ir(adm, "clientes.php")
    shot(adm, "clientes", full_page=True)
    adm.click("button[data-bs-target='#modalAgregar']")
    adm.fill("#modalAgregar input[name=nombre]", "Sofía Herrera")
    adm.fill("#modalAgregar input[name=documento]", "1001234567")
    adm.click("#modalAgregar button[name=guardar_cliente]")
    adm.wait_for_selector(".swal2-toast"); adm.wait_for_timeout(500)
    shot(adm.locator(".swal2-toast"), "toast")

    for pagina in ["reportes", "usuarios", "backup", "configuracion"]:
        ir(adm, f"{pagina}.php")
        shot(adm, pagina, full_page=True)

    print("== Celular")
    mob = br.new_context(viewport={"width": 390, "height": 844}, device_scale_factor=2, locale="es-CO")
    mob.add_cookies(actx.cookies())
    mp = mob.new_page()
    ir(mp, "dashboard.php")
    shot(mp, "celular_dashboard")
    mp.click(".topbar-toggle"); mp.wait_for_timeout(500)
    shot(mp, "celular_menu")
    mob.close()
    br.close()
print("Capturas listas en", OUT)
