<?php
/**
 * Generador mínimo de archivos Excel (.xlsx) sin dependencias (no requiere la extensión zip).
 * Soporta varias hojas, estilos, formatos de moneda/fecha, celdas combinadas, filtros,
 * paneles inmovilizados, totales con fórmulas y configuración de impresión.
 *
 * Uso:
 *   $x  = new Xlsx('Reporte de Ventas');
 *   $sh = $x->sheet('Ventas');
 *   $x->header($sh, 'Reporte de Ventas', ['Periodo: …'], 6);
 *   $x->table($sh, [['Fecha','datetime',18], ['Total','money',14]], $filas, ['label' => 'TOTALES', 'sum' => [1]]);
 *   $x->download('ventas.xlsx');
 */

class XlsxSheet {
    public array $rows = [];      // n° fila => [n° col => ['v'=>, 's'=>, 'f'=>]]
    public array $heights = [];
    public array $widths = [];    // n° col (0-based) => ancho
    public array $merges = [];
    public ?int $freezeRow = null;
    public ?string $filter = null;
    public int $next = 1;         // siguiente fila libre
    public bool $landscape = true;
    public function __construct(public string $name) {}
}

class Xlsx {
    private array $sheets = [];
    private array $xfKeys = [];
    private array $xfDefs = [];

    // Paleta de la marca
    const FONTS = [
        'normal'   => '<font><sz val="10"/><color rgb="FF1E293B"/><name val="Calibri"/></font>',
        'bold'     => '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>',
        'title'    => '<font><b/><sz val="18"/><color rgb="FF0F172A"/><name val="Calibri"/></font>',
        'subtitle' => '<font><sz val="10"/><color rgb="FF64748B"/><name val="Calibri"/></font>',
        'header'   => '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>',
        'red'      => '<font><b/><sz val="10"/><color rgb="FFDC2626"/><name val="Calibri"/></font>',
        'accent'   => '<font><b/><sz val="14"/><color rgb="FF1D4ED8"/><name val="Calibri"/></font>',
        'section'  => '<font><b/><sz val="12"/><color rgb="FF1D4ED8"/><name val="Calibri"/></font>',
        'brand'    => '<font><b/><sz val="9"/><color rgb="FF8B5CF6"/><name val="Calibri"/></font>',
    ];
    const FILLS = [
        'none'   => '<fill><patternFill patternType="none"/></fill>',
        'gray125'=> '<fill><patternFill patternType="gray125"/></fill>',
        'navy'   => '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>',
        'zebra'  => '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>',
        'totals' => '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/><bgColor indexed="64"/></patternFill></fill>',
        'kpi'    => '<fill><patternFill patternType="solid"><fgColor rgb="FFEFF6FF"/><bgColor indexed="64"/></patternFill></fill>',
    ];
    const BORDERS = [
        'none'  => '<border><left/><right/><top/><bottom/><diagonal/></border>',
        'grid'  => '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom><diagonal/></border>',
        'total' => '<border><left/><right/><top style="medium"><color rgb="FF0F172A"/></top><bottom style="double"><color rgb="FF0F172A"/></bottom><diagonal/></border>',
        'title' => '<border><left/><right/><top/><bottom style="medium"><color rgb="FF3B82F6"/></bottom><diagonal/></border>',
    ];
    const NUMFMTS = [   // tipo => [id, código]
        'money'    => [164, '"$"#,##0.00'],
        'datetime' => [165, 'dd/mm/yyyy hh:mm'],
        'date'     => [166, 'dd/mm/yyyy'],
        'int'      => [167, '#,##0'],
        'pct'      => [168, '0.0%'],
        'text'     => [49, '@'],
    ];

    public function __construct(private string $title = 'Reporte') {
        $this->style([]);   // índice 0 = estilo por defecto
    }

    public function sheet(string $name): XlsxSheet {
        $name = mb_substr(preg_replace('/[\[\]\:\*\?\/\\\\]/', '', $name), 0, 31);
        return $this->sheets[] = new XlsxSheet($name);
    }

    /** Registra un estilo y devuelve su índice. Claves: font, fill, border, fmt, h (alineación), wrap */
    public function style(array $s): int {
        $s += ['font' => 'normal', 'fill' => 'none', 'border' => 'none', 'fmt' => null, 'h' => null, 'wrap' => false];
        $k = json_encode($s);
        if (!isset($this->xfKeys[$k])) { $this->xfKeys[$k] = count($this->xfDefs); $this->xfDefs[] = $s; }
        return $this->xfKeys[$k];
    }

    /** Estilo de celda de datos según su tipo */
    public function cellStyle(string $type, bool $zebra = false, bool $red = false): int {
        $num = in_array($type, ['money', 'int', 'pct'], true);
        return $this->style([
            'font' => $red ? 'red' : 'normal', 'fill' => $zebra ? 'zebra' : 'none', 'border' => 'grid',
            'fmt' => isset(self::NUMFMTS[$type]) && $type !== 'text' ? $type : null, 'h' => $num ? 'right' : (in_array($type, ['date', 'datetime', 'center'], true) ? 'center' : 'left'),
        ]);
    }

    public function set(XlsxSheet $sh, int $row, int $col, $v, int $s = 0, ?string $f = null): void {
        $sh->rows[$row][$col] = ['v' => $v, 's' => $s, 'f' => $f];
    }

    /** Encabezado del reporte: marca, título, líneas de detalle */
    public function header(XlsxSheet $sh, string $title, array $lines, int $ncols): void {
        $r = $sh->next;
        $this->set($sh, $r, 0, APP_NAME . ' by ' . APP_AUTHOR, $this->style(['font' => 'brand']));
        $sh->heights[$r] = 16;
        $r++;
        $this->set($sh, $r, 0, $title, $this->style(['font' => 'title', 'border' => 'title']));
        for ($c = 1; $c < $ncols; $c++) $this->set($sh, $r, $c, null, $this->style(['font' => 'title', 'border' => 'title']));
        $sh->merges[] = 'A' . $r . ':' . self::col($ncols - 1) . $r;
        $sh->heights[$r] = 30;
        $r++;
        foreach ($lines as $l) {
            $this->set($sh, $r, 0, $l, $this->style(['font' => 'subtitle']));
            $sh->merges[] = 'A' . $r . ':' . self::col($ncols - 1) . $r;
            $r++;
        }
        $sh->next = $r + 1;
    }

    /** Título de sección dentro de una hoja */
    public function section(XlsxSheet $sh, string $text): void {
        $this->set($sh, $sh->next, 0, $text, $this->style(['font' => 'section']));
        $sh->heights[$sh->next] = 22;
        $sh->next++;
    }

    /** Indicadores: pares etiqueta / valor en dos columnas */
    public function kpis(XlsxSheet $sh, array $items, int $col = 0): void {
        foreach ($items as [$label, $value, $type]) {
            $r = $sh->next++;
            $this->set($sh, $r, $col, $label, $this->style(['font' => 'bold', 'fill' => 'kpi', 'border' => 'grid']));
            $this->set($sh, $r, $col + 1, $value, $this->style(['font' => 'accent', 'fill' => 'kpi', 'border' => 'grid', 'fmt' => isset(self::NUMFMTS[$type]) && $type !== 'text' ? $type : null, 'h' => 'right']));
            $sh->heights[$r] = 24;
        }
        $sh->next++;
    }

    /**
     * Tabla con encabezado, filas alternadas y fila de totales.
     * $cols: [[título, tipo, ancho], …] tipos: text, money, int, pct, date, datetime, center
     * $totals: ['label' => 'TOTALES', 'sum' => [índices de columna], 'count' => índice]
     * $opts: ['filter' => true, 'freeze' => true, 'red' => fn($fila) => [índices en rojo]]
     */
    public function table(XlsxSheet $sh, array $cols, array $rows, array $totals = [], array $opts = []): void {
        $opts += ['filter' => true, 'freeze' => true, 'red' => null];
        $h = $sh->next;
        foreach ($cols as $i => [$label, $type, $width]) {
            $this->set($sh, $h, $i, $label, $this->style(['font' => 'header', 'fill' => 'navy', 'border' => 'grid', 'h' => 'center', 'wrap' => true]));
            $sh->widths[$i] = max($sh->widths[$i] ?? 0, $width);
        }
        $sh->heights[$h] = 30;
        $r = $h + 1;
        foreach (array_values($rows) as $n => $row) {
            $red = $opts['red'] ? ($opts['red'])($row) : [];
            foreach ($cols as $i => [, $type]) {
                $v = $row[$i] ?? null;
                if (($type === 'date' || $type === 'datetime') && is_string($v) && $v !== '') $v = self::excelDate($v);
                if (in_array($type, ['money', 'int', 'pct'], true) && $v !== null && $v !== '') $v = (float)$v;
                $this->set($sh, $r, $i, $v, $this->cellStyle($type, $n % 2 === 1, in_array($i, $red, true)));
            }
            $sh->heights[$r] = 18;
            $r++;
        }
        $last = $r - 1;
        if (!$rows) {   // tabla vacía: una fila indicándolo
            $this->set($sh, $r, 0, 'Sin registros en este periodo', $this->style(['font' => 'subtitle', 'border' => 'grid']));
            $sh->merges[] = 'A' . $r . ':' . self::col(count($cols) - 1) . $r;
            $last = $r++;
        }
        if ($totals) {
            $tot = $this->style(['font' => 'bold', 'fill' => 'totals', 'border' => 'total']);
            foreach ($cols as $i => [, $type]) $this->set($sh, $r, $i, null, $tot);
            $this->set($sh, $r, 0, $totals['label'] ?? 'TOTALES', $tot);
            foreach ($totals['sum'] ?? [] as $i) {
                $L = self::col($i);
                $cached = array_sum(array_map(fn($row) => (float)($row[$i] ?? 0), $rows));
                $this->set($sh, $r, $i, $cached, $this->style(['font' => 'bold', 'fill' => 'totals', 'border' => 'total', 'fmt' => $cols[$i][1], 'h' => 'right']),
                    $rows ? "SUBTOTAL(9,{$L}" . ($h + 1) . ":{$L}{$last})" : null);
            }
            if (isset($totals['count'])) {
                $i = $totals['count']; $L = self::col($i);
                $this->set($sh, $r, $i, count($rows), $this->style(['font' => 'bold', 'fill' => 'totals', 'border' => 'total', 'fmt' => 'int', 'h' => 'right']),
                    $rows ? "SUBTOTAL(3,{$L}" . ($h + 1) . ":{$L}{$last})" : null);
            }
            $sh->heights[$r] = 22;
            $r++;
        }
        if ($opts['freeze'] && $sh->freezeRow === null) $sh->freezeRow = $h;
        if ($opts['filter'] && $sh->filter === null && $rows) $sh->filter = 'A' . $h . ':' . self::col(count($cols) - 1) . $last;
        $sh->next = $r + 1;
    }

    public function download(string $filename): void {
        $data = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-cache');
        echo $data;
        exit;
    }

    // ───────────────────────── utilidades ─────────────────────────
    public static function col(int $i): string {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    /** "2026-10-04 11:33:16" → número de serie de Excel */
    public static function excelDate(string $s): ?float {
        $ts = strtotime($s);
        if ($ts === false) return null;
        [$y, $m, $d, $H, $i, $S] = explode(',', date('Y,n,j,G,i,s', $ts));
        $days = (gmmktime(0, 0, 0, (int)$m, (int)$d, (int)$y) / 86400) + 25569;
        return $days + ((int)$H * 3600 + (int)$i * 60 + (int)$S) / 86400;
    }

    private static function esc($s): string {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function build(): string {
        if (!$this->sheets) $this->sheet('Hoja1');
        $files = [];
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['[Content_Types].xml'] = $ct . '</Types>';
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::esc($this->title) . '</dc:title><dc:creator>' . self::esc(APP_NAME . ' by ' . APP_AUTHOR) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>';

        $wbSheets = $defined = $rels = '';
        foreach ($this->sheets as $i => $sh) {
            $id = $i + 1;
            $wbSheets .= '<sheet name="' . self::esc($sh->name) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            if ($sh->filter) {
                [$a, $b] = explode(':', $sh->filter);
                $abs = fn($ref) => preg_replace('/^([A-Z]+)(\d+)$/', '\$$1\$$2', $ref);
                $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::esc($sh->name) . '\'!' . $abs($a) . ':' . $abs($b) . '</definedName>';
            }
            $files['xl/worksheets/sheet' . $id . '.xml'] = $this->sheetXml($sh, $i === 0);
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets>' . ($defined ? '<definedNames>' . $defined . '</definedNames>' : '') . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
        $files['xl/styles.xml'] = $this->stylesXml();
        return self::zip($files);
    }

    private function sheetXml(XlsxSheet $sh, bool $selected): string {
        $maxCol = 0; $maxRow = 1;
        foreach ($sh->rows as $r => $cells) { $maxRow = max($maxRow, $r); $maxCol = max($maxCol, max(array_keys($cells))); }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $x .= '<dimension ref="A1:' . self::col($maxCol) . $maxRow . '"/>';
        $x .= '<sheetViews><sheetView showGridLines="0" workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>';
        if ($sh->freezeRow) $x .= '<pane ySplit="' . $sh->freezeRow . '" topLeftCell="A' . ($sh->freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . ($sh->freezeRow + 1) . '" sqref="A' . ($sh->freezeRow + 1) . '"/>';
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($sh->widths) {
            ksort($sh->widths);
            $x .= '<cols>';
            foreach ($sh->widths as $c => $w) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($sh->rows);
        foreach ($sh->rows as $r => $cells) {
            ksort($cells);
            $x .= '<row r="' . $r . '"' . (isset($sh->heights[$r]) ? ' ht="' . $sh->heights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cells as $c => $cell) {
                $ref = self::col($c) . $r;
                $s = $cell['s'] ? ' s="' . $cell['s'] . '"' : '';
                $v = $cell['v'];
                if ($cell['f'] !== null) {
                    $x .= '<c r="' . $ref . '"' . $s . '><f>' . self::esc($cell['f']) . '</f><v>' . (float)$v . '</v></c>';
                } elseif ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '"' . $s . '/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $s . '><v>' . (is_finite($v) ? $v : 0) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh->filter) $x .= '<autoFilter ref="' . $sh->filter . '"/>';
        if ($sh->merges) {
            $x .= '<mergeCells count="' . count($sh->merges) . '">';
            foreach ($sh->merges as $m) $x .= '<mergeCell ref="' . $m . '"/>';
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.3" footer="0.3"/>';
        $x .= '<pageSetup orientation="' . ($sh->landscape ? 'landscape' : 'portrait') . '" paperSize="9" fitToWidth="1" fitToHeight="0"/>';
        $x .= '<headerFooter><oddFooter>&amp;L&amp;8' . self::esc(APP_NAME) . ' · ' . self::esc($this->title) . '&amp;R&amp;8Página &amp;P de &amp;N</oddFooter></headerFooter>';
        return $x . '</worksheet>';
    }

    private function stylesXml(): string {
        $fontIdx = array_flip(array_keys(self::FONTS));
        $fillIdx = array_flip(array_keys(self::FILLS));
        $bordIdx = array_flip(array_keys(self::BORDERS));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="' . (count(self::NUMFMTS) - 1) . '">';
        foreach (self::NUMFMTS as [$id, $code]) if ($id >= 164) $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
        $x .= '</numFmts><fonts count="' . count(self::FONTS) . '">' . implode('', self::FONTS) . '</fonts>';
        $x .= '<fills count="' . count(self::FILLS) . '">' . implode('', self::FILLS) . '</fills>';
        $x .= '<borders count="' . count(self::BORDERS) . '">' . implode('', self::BORDERS) . '</borders>';
        $x .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $x .= '<cellXfs count="' . count($this->xfDefs) . '">';
        foreach ($this->xfDefs as $d) {
            $fmt = $d['fmt'] ? self::NUMFMTS[$d['fmt']][0] : 0;
            $x .= '<xf numFmtId="' . $fmt . '" fontId="' . $fontIdx[$d['font']] . '" fillId="' . $fillIdx[$d['fill']] . '" borderId="' . $bordIdx[$d['border']] . '" xfId="0"'
                . ' applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
                . '<alignment vertical="center"' . ($d['h'] ? ' horizontal="' . ($d['h'] === 'center' ? 'center' : $d['h']) . '"' : '') . ($d['wrap'] ? ' wrapText="1"' : '') . '/></xf>';
        }
        $x .= '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
        return $x;
    }

    /** ZIP con compresión deflate, escrito a mano (no necesita ZipArchive) */
    private static function zip(array $files): string {
        $out = ''; $central = ''; $n = 0;
        $t = getdate();
        $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        foreach ($files as $name => $data) {
            $crc = crc32($data);
            $comp = gzdeflate($data, 6);
            $offset = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0) . $name . $comp;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $n++;
        }
        return $out . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($out), 0);
    }
}
