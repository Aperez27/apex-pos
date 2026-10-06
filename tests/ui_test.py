"""Pruebas de interfaz de APEX en un navegador real (Microsoft Edge vía Playwright).

Uso:  python tests/ui_test.py [URL]      (por defecto http://localhost/<carpeta>)
No abre ni cierra la caja real: solo navega, prueba formularios en pantalla y limpia lo que crea.
Usa un administrador temporal (zzadmin) que se borra al final.
Variables opcionales: MYSQL_BIN, PHP_BIN, MYSQL_USER, MYSQL_PASS, APEX_DB_NAME,
APEX_BROWSER (msedge por defecto en Windows; "chromium" usa el navegador de Playwright).
"""
import os, re, shutil, subprocess, sys, tempfile
from playwright.sync_api import sync_playwright

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = sys.argv[1] if len(sys.argv) > 1 else f"http://localhost/{os.path.basename(RAIZ)}"
XAMPP = r"C:\xampp"
MYSQL = os.environ.get("MYSQL_BIN") or (XAMPP + r"\mysql\bin\mysql.exe" if os.path.exists(XAMPP) else shutil.which("mysql"))
PHP = os.environ.get("PHP_BIN") or (XAMPP + r"\php\php.exe" if os.path.exists(XAMPP) else shutil.which("php"))
DB = os.environ.get("APEX_DB_NAME", "apex_db")
NAVEGADOR = os.environ.get("APEX_BROWSER", "msedge" if os.name == "nt" else "chromium")
ADM, ADM_PASS = "zzadmin", "ZzPrueba2026"
ok = fail = 0


def sql(q):
    cred = ["-u" + os.environ.get("MYSQL_USER", "root")] + (["-p" + os.environ["MYSQL_PASS"]] if os.environ.get("MYSQL_PASS") else [])
    r = subprocess.run([MYSQL, *cred, "--default-character-set=utf8mb4", DB, "-N", "-e", q], capture_output=True, text=True)
    return r.stdout.strip()


def check(nombre, cond, detalle=""):
    global ok, fail
    if cond:
        ok += 1; print(f"  PASS  {nombre}")
    else:
        fail += 1; print(f"  FAIL  {nombre} {detalle}")


def vigilar(page, errores):
    page.on("console", lambda m: errores.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errores.append(str(e)))


def login(ctx, usuario, clave):
    pg = ctx.new_page()
    pg.goto(f"{B}/index.php")
    pg.fill("input[name=usuario]", usuario)
    pg.fill("input[name=password]", clave)
    pg.click("button[type=submit]")
    pg.wait_for_url("**/dashboard.php")
    return pg


hash_adm = subprocess.run([PHP, "-r", f"echo password_hash('{ADM_PASS}', PASSWORD_DEFAULT);"], capture_output=True, text=True).stdout
sql(f"DELETE FROM usuarios WHERE usuario='{ADM}'; INSERT INTO usuarios (nombre,usuario,password,rol) VALUES ('ZZ Admin Pruebas','{ADM}','{hash_adm}','admin')")

with sync_playwright() as p:
    br = p.chromium.launch(channel=None if NAVEGADOR == "chromium" else NAVEGADOR, headless=True)
    ctx = br.new_context(accept_downloads=True)

    print("== Login y páginas (errores JS / CSP)")
    pg = ctx.new_page(); errs = []; vigilar(pg, errs)
    pg.goto(f"{B}/index.php"); pg.wait_for_load_state("networkidle")
    check("login sin errores de consola", not errs, errs)
    check("login muestra copyright", "APEX by Ing. Antony Pérez" in pg.content())
    pg.close()

    pg = login(ctx, ADM, ADM_PASS)
    paginas = ["dashboard", "ventas", "abonos", "caja", "clientes", "proveedores", "productos", "averiados",
               "reportes", "usuarios", "backup", "configuracion"]
    for nombre in paginas:
        errs = []; vigilar(pg, errs)
        pg.goto(f"{B}/{nombre}.php"); pg.wait_for_load_state("networkidle")
        csp = [e for e in errs if "Content Security Policy" in e or "Refused" in e]
        check(f"{nombre}.php sin errores JS ni bloqueos CSP", not errs, errs[:2])
        pg.remove_listener("console", pg.listeners("console")[0]) if hasattr(pg, "listeners") else None
    # Iconos de Font Awesome cargados (la CSP permite sus fuentes)
    pg.goto(f"{B}/dashboard.php"); pg.wait_for_load_state("networkidle")
    check("iconos Font Awesome cargan", pg.evaluate("document.fonts.check('900 16px \"Font Awesome 6 Free\"')"))

    print("== Seguridad en el navegador")
    pg.goto(f"{B}/clientes.php")
    pg.click("button[data-bs-target='#modalAgregar']")
    pg.fill("#modalAgregar input[name=nombre]", 'ZZXSS<img src=x onerror="window.__xss=1">')
    pg.fill("#modalAgregar input[name=documento]", "ZZXSSUI")
    pg.click("#modalAgregar button[name=guardar_cliente]")
    pg.wait_for_load_state("networkidle"); pg.wait_for_timeout(700)
    check("nombre con código no se ejecuta (XSS)", pg.evaluate("window.__xss") is None)
    check("el nombre se muestra como texto", "ZZXSS<img" in pg.inner_text("body"))
    sql("DELETE FROM clientes WHERE documento='ZZXSSUI'")

    print("== Formularios")
    pg.goto(f"{B}/productos.php")
    pg.click("button[data-bs-target='#modalAgregar']")
    vis = pg.locator("#modalAgregar input.money-input").last
    vis.fill("1250000.5")
    check("campo de dinero con separador de miles", vis.input_value() == "1,250,000.5", vis.input_value())
    check("valor real enviado sin formato", pg.locator("#modalAgregar input[name=precio_venta]").input_value() == "1250000.5")
    pg.click("#modalAgregar button:has-text('Agregar color')")
    check("agregar fila de color", pg.locator("#modalAgregar .color-row").count() == 2)
    pg.keyboard.press("Escape")
    if pg.locator("[data-bs-target='#modalCategoria']").count():
        pg.wait_for_timeout(400)
        pg.locator("[data-bs-target='#modalCategoria']").first.click()
        pg.wait_for_selector("#modalCategoria.show")
        check("renombrar categoría trae el nombre actual", pg.input_value("#cat_nombre") != "")
        pg.keyboard.press("Escape")

    caja = sql("SELECT id FROM cajas WHERE estado='Abierta' LIMIT 1")
    if caja:
        pg.goto(f"{B}/caja.php")
        esperado = float(pg.get_attribute("#esperado", "data-valor"))
        pg.locator("#formCierre input.money-input").fill(str(int(esperado) + 1000))
        txt = pg.inner_text("#difCierre")
        check("arqueo muestra sobrante al escribir (sin cerrar)", txt.startswith("Sobrante"), txt)
        pg.goto(f"{B}/caja.php")   # se descarta el formulario sin enviarlo

        prod = sql("SELECT id FROM productos WHERE stock>0 ORDER BY id LIMIT 1")
        if prod:
            pg.goto(f"{B}/ventas.php")
            pg.select_option("#selectProd", prod)
            pg.click("button[name=agregar]")
            pg.wait_for_load_state("networkidle")
            filas = pg.locator("a[href^='?eliminar=']").count()
            check("agregar producto al carrito", filas >= 1)
            check("botón Cobrar habilitado con caja abierta", pg.locator("button[name=procesar_venta]").is_enabled())
            pg.select_option("#estadoVenta", "Mixto")
            check("pago mixto muestra el desglose", pg.locator(".metodo-mixto").first.is_visible())
            # vaciar el carrito (solo sesión, no se registra venta)
            while pg.locator("a[href^='?eliminar=']").count():
                pg.goto(f"{B}/ventas.php" + pg.get_attribute("a[href^='?eliminar=']", "href"))
            check("carrito vaciado", pg.locator("a[href^='?eliminar=']").count() == 0)
    else:
        print("  (no hay caja abierta: se omiten arqueo y carrito)")

    print("== Reportes y PDF")
    pg.goto(f"{B}/reportes.php?descuadres=todos")
    check("reportes muestra sección de descuadres", pg.locator("#descuadres").count() == 1)
    cerrada = sql("SELECT MAX(id) FROM cajas WHERE estado='Cerrada'")
    if cerrada and cerrada != "NULL":
        pg.goto(f"{B}/caja_reporte.php?id={cerrada}"); pg.wait_for_load_state("networkidle")
        with pg.expect_download(timeout=60000) as d:
            pg.click("button.b-pdf")
        ruta = os.path.join(tempfile.gettempdir(), d.value.suggested_filename)
        d.value.save_as(ruta)
        with open(ruta, "rb") as f:
            cab = f.read(5)
        check("PDF del cierre se descarga", cab == b"%PDF-" and os.path.getsize(ruta) > 20000, f"{os.path.getsize(ruta)} bytes")
        check("nombre del PDF", re.match(r"Cierre_caja_\d{4}_\d{4}-\d{2}-\d{2}\.pdf", d.value.suggested_filename), d.value.suggested_filename)
        os.remove(ruta)
    venta = sql("SELECT MAX(id) FROM ventas")
    if venta and venta != "NULL":
        pg.goto(f"{B}/ticket.php?id={venta}")
        check("ticket muestra sistema APEX", "Sistema APEX by Ing. Antony Pérez" in pg.inner_text("body"))

    print("== Celular (390 px)")
    mob = br.new_context(viewport={"width": 390, "height": 844})
    for c in ctx.cookies():
        mob.add_cookies([c])
    mp = mob.new_page()
    for nombre in paginas:
        mp.goto(f"{B}/{nombre}.php"); mp.wait_for_load_state("networkidle")
        ancho = mp.evaluate("document.documentElement.scrollWidth - window.innerWidth")
        check(f"{nombre}.php sin desborde horizontal en celular", ancho <= 1, f"{ancho}px")
    mob.close()

    print("== Vendedor")
    pg.goto(f"{B}/usuarios.php")
    pg.click("button[data-bs-target='#modalCrear']")
    pg.fill("#modalCrear input[name=nombre]", "ZZ Vendedor UI")
    pg.fill("#modalCrear input[name=usuario]", "zzvendui")
    pg.fill("#modalCrear input[name=password]", "clave12345")
    pg.click("#modalCrear button[name=crear_usuario]")
    pg.wait_for_load_state("networkidle")
    vctx = br.new_context()
    vp = login(vctx, "zzvendui", "clave12345")
    menu = vp.inner_text(".sidebar-nav")
    check("vendedor ve Caja en el menú", "Caja" in menu)
    check("vendedor no ve Proveedores ni Inventario", "Proveedores" not in menu and "Inventario" not in menu)
    vp.goto(f"{B}/caja.php")
    check("vendedor no ve egresos manuales", vp.locator("button[name=movimiento]").count() == 0)
    vp.goto(f"{B}/proveedores.php")
    check("vendedor: proveedores denegado", "Acceso denegado" in vp.inner_text("body"))
    vp.goto(f"{B}/logout.php")
    check("cerrar sesión vuelve al login", vp.url.endswith("index.php"))
    vctx.close()
    sql("DELETE FROM usuarios WHERE usuario='zzvendui'")

    br.close()

sql(f"DELETE FROM usuarios WHERE usuario='{ADM}'; DELETE FROM login_intentos WHERE usuario LIKE 'zz%'")
print(f"\nRESULTADO UI: {ok} pasaron, {fail} fallaron")
sys.exit(1 if fail else 0)
