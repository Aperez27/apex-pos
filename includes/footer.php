    </div><!-- /content-area -->
    <footer class="app-footer"><?= h(app_copyright()) ?> <span class="ms-1">· v<?= APP_VERSION ?></span></footer>
</div><!-- /main-wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .swal-rp { border-radius: 16px !important; font-family: system-ui,-apple-system,"Segoe UI",sans-serif; }
    .swal-rp .swal2-title { font-size: 1.25rem; color: #1e293b; }
    .swal-rp .swal2-html-container { font-size: .92rem; color: #64748b; }
    .money-input { font-variant-numeric: tabular-nums; font-weight: 600; letter-spacing: .01em; }
    .swal2-toast.swal-rp, .swal2-popup.swal2-toast { border-radius: 12px !important; box-shadow: 0 10px 30px rgba(15,23,42,.15) !important; }
</style>
<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('sidebarOverlay').classList.add('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}
(function tick() {
    const d = new Date(), p = n => String(n).padStart(2,'0');
    let h = d.getHours(), ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12 || 12;
    const el = document.getElementById('reloj');
    if (el) el.textContent = `${p(h)}:${p(d.getMinutes())}:${p(d.getSeconds())} ${ap}`;
    setTimeout(tick, 1000);
})();
// ── Campos de dinero con separador de miles mientras se escribe ──
// Aplica a <input type="number" step="0.01"> (precios, montos, descuentos). El input original
// pasa a ser hidden con el valor limpio (1250000.5) que llega al servidor; el usuario escribe
// en una copia de texto que muestra 1,250,000.5. Para excluir un campo: data-no-format.
const MONEY_THOUSANDS = ',', MONEY_DECIMAL = '.';
const valueProp = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

function moneyParse(text) {            // texto escrito -> {raw, display}
    let s = String(text).replace(new RegExp('[^0-9' + (MONEY_DECIMAL === '.' ? '\\.' : MONEY_DECIMAL) + ']', 'g'), '');
    const i = s.indexOf(MONEY_DECIMAL);
    let int = i < 0 ? s : s.slice(0, i);
    let dec = i < 0 ? null : s.slice(i + 1).split(MONEY_DECIMAL).join('').slice(0, 2);
    int = int.replace(/^0+(?=\d)/, '');
    if (int === '' && dec !== null) int = '0';
    const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, MONEY_THOUSANDS);
    return {
        raw: int === '' ? '' : int + (dec !== null && dec !== '' ? '.' + dec : ''),
        display: grouped + (dec !== null ? MONEY_DECIMAL + dec : ''),
    };
}
function moneyFromRaw(raw) {           // "1150.00" -> "1,150.00"
    if (raw === '' || raw == null) return '';
    const [int, dec] = String(raw).split('.');
    return moneyParse(int + (dec !== undefined ? MONEY_DECIMAL + dec : '')).display;
}
function enhanceMoney(orig) {
    if (orig.dataset.money || orig.hasAttribute('data-no-format')) return;
    orig.dataset.money = '1';
    const vis = document.createElement('input');
    vis.type = 'text';
    vis.inputMode = 'decimal';
    vis.autocomplete = 'off';
    vis.className = orig.className + ' money-input';
    vis.placeholder = orig.placeholder;
    vis.required = orig.required;
    vis.value = moneyFromRaw(orig.value);
    orig.required = false;
    orig.type = 'hidden';
    orig.after(vis);

    // Si otro script asigna orig.value (p. ej. modal de edición), se refleja en el campo visible
    Object.defineProperty(orig, 'value', {
        get() { return valueProp.get.call(this); },
        set(v) { valueProp.set.call(this, v); vis.value = moneyFromRaw(v); validate(); },
    });

    function validate() {
        const raw = valueProp.get.call(orig), n = parseFloat(raw);
        const min = orig.getAttribute('min'), max = orig.getAttribute('max');
        let msg = '';
        if (raw !== '' && min !== null && n < parseFloat(min)) msg = 'El valor mínimo es ' + moneyFromRaw(min);
        else if (raw !== '' && max !== null && max !== '' && n > parseFloat(max)) msg = 'El valor máximo es ' + moneyFromRaw(max);
        vis.setCustomValidity(msg);
    }
    vis.addEventListener('input', () => {
        // Conservar la posición del cursor contando los dígitos a su derecha
        const right = vis.value.slice(vis.selectionStart).replace(/[^0-9.,]/g, '').replace(new RegExp('\\' + MONEY_THOUSANDS, 'g'), '').length;
        const r = moneyParse(vis.value);
        vis.value = r.display;
        let pos = vis.value.length, count = 0;
        while (pos > 0 && count < right) { pos--; if (vis.value[pos] !== MONEY_THOUSANDS) count++; }
        vis.setSelectionRange(pos, pos);
        valueProp.set.call(orig, r.raw);
        validate();
        orig.dispatchEvent(new Event('input', { bubbles: true }));   // dispara oninput original (recalcTotal)
    });
    vis.addEventListener('focus', () => { if (/^0(\.0+)?$/.test(valueProp.get.call(orig))) vis.select(); });
    vis.addEventListener('invalid', validate);
    validate();
}
const MONEY_SEL = 'input[type=number][step="0.01"]';
document.querySelectorAll(MONEY_SEL).forEach(enhanceMoney);
new MutationObserver(muts => muts.forEach(m => m.addedNodes.forEach(n => {
    if (n.nodeType !== 1) return;
    if (n.matches(MONEY_SEL)) enhanceMoney(n);
    n.querySelectorAll(MONEY_SEL).forEach(enhanceMoney);
}))).observe(document.body, { childList: true, subtree: true });
// Revalidar min/max al pulsar enviar (antes de la validación del navegador), por si el límite
// cambió después de escribir, p. ej. el saldo máximo de un abono al cambiar de cuenta
document.addEventListener('click', e => {
    const btn = e.target.closest('button[type=submit], button:not([type]), input[type=submit]');
    if (btn && btn.form) btn.form.querySelectorAll('.money-input').forEach(v => v.dispatchEvent(new Event('invalid')));
}, true);

// ── SweetAlert2: estilo común ──
const SwalRP = Swal.mixin({
    customClass: { popup: 'swal-rp', confirmButton: 'btn btn-primary px-4 mx-1', cancelButton: 'btn btn-outline-secondary px-4 mx-1' },
    buttonsStyling: false,
});
const ToastRP = Swal.mixin({
    toast: true, position: 'top-end', showConfirmButton: false,
    timer: 3500, timerProgressBar: true,
    didOpen: t => { t.addEventListener('mouseenter', Swal.stopTimer); t.addEventListener('mouseleave', Swal.resumeTimer); },
});

// Confirmaciones: cualquier <form> o <a> con data-confirm="mensaje"
document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!f.dataset.confirm || f.dataset.confirmed) return;
    e.preventDefault();
    confirmar(f.dataset.confirm, f.dataset.confirmBtn).then(ok => { if (ok) { f.dataset.confirmed = '1'; f.submit(); } });
});
document.addEventListener('click', function (e) {
    const a = e.target.closest('a[data-confirm]');
    if (!a) return;
    e.preventDefault();
    confirmar(a.dataset.confirm, a.dataset.confirmBtn).then(ok => { if (ok) window.location.href = a.href; });
});
function confirmar(texto, boton) {
    return SwalRP.fire({
        title: '¿Estás seguro?', text: texto, icon: 'warning',
        showCancelButton: true, reverseButtons: true, focusCancel: true,
        confirmButtonText: boton || 'Sí, eliminar', cancelButtonText: 'Cancelar',
        customClass: { popup: 'swal-rp', confirmButton: 'btn btn-danger px-4 mx-1', cancelButton: 'btn btn-outline-secondary px-4 mx-1' },
    }).then(r => r.isConfirmed);
}

// Mensajes tras una acción: .alert-dismiss → toast (éxito) o modal (error/advertencia)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert-dismiss').forEach(a => {
        const tipo  = a.classList.contains('alert-danger') ? 'error'
                    : a.classList.contains('alert-warning') ? 'warning'
                    : a.classList.contains('alert-info') ? 'info' : 'success';
        const link  = a.querySelector('a[href]');
        if (link) link.remove();
        const texto = a.textContent.trim();
        a.remove();
        if (link) {
            // Venta registrada: ofrecer imprimir ticket
            SwalRP.fire({ icon: 'success', titleText: texto, showCancelButton: true,   // titleText: siempre texto plano (evita XSS)
                confirmButtonText: '<i class="fa-solid fa-receipt me-1"></i> Imprimir Ticket', cancelButtonText: 'Cerrar' })
              .then(r => { if (r.isConfirmed) window.open(link.href, '_blank'); });
        } else if (tipo === 'success' || tipo === 'info') {
            ToastRP.fire({ icon: tipo, titleText: texto });
        } else {
            SwalRP.fire({ icon: tipo, title: tipo === 'error' ? 'No se pudo completar' : 'Atención', text: texto, confirmButtonText: 'Entendido' });
        }
    });
});
</script>
</body>
</html>
