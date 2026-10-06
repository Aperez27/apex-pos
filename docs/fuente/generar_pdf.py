"""Convierte los manuales HTML de docs/fuente a PDF (en docs/) con Microsoft Edge.

    python docs/fuente/generar_pdf.py
"""
import os, pathlib
from playwright.sync_api import sync_playwright

FUENTE = pathlib.Path(__file__).resolve().parent
DOCS = FUENTE.parent
MANUALES = [
    ("01_Manual_de_Usuario", "Manual de Usuario"),
    ("02_Manual_de_Instalacion", "Manual de Instalación"),
    ("03_Manual_Tecnico", "Manual Técnico"),
    ("04_Guia_Rapida", "Guía Rápida"),
]
PIE = """<div style="width:100%;font-size:7.5pt;color:#64748b;padding:0 17mm;display:flex;justify-content:space-between;font-family:'Segoe UI',sans-serif">
<span>APEX · {titulo}</span><span>© APEX by Ing. Antony Pérez</span><span>Página <span class="pageNumber"></span> de <span class="totalPages"></span></span></div>"""

with sync_playwright() as p:
    navegador = os.environ.get("APEX_BROWSER", "msedge" if os.name == "nt" else "chromium")
    br = p.chromium.launch(channel=None if navegador == "chromium" else navegador, headless=True)
    pg = br.new_page()
    for archivo, titulo in MANUALES:
        html = FUENTE / f"{archivo}.html"
        pg.goto(html.as_uri())
        pg.wait_for_load_state("networkidle")
        pg.pdf(path=str(DOCS / f"{archivo}.pdf"), format="Letter", print_background=True, prefer_css_page_size=True,
               display_header_footer=True, header_template="<span></span>", footer_template=PIE.format(titulo=titulo))
        print(f"{archivo}.pdf  {os.path.getsize(DOCS / f'{archivo}.pdf') // 1024} KB")
    br.close()
