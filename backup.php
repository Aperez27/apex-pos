<?php
require 'config/conexion.php';
if ($rol !== 'admin') { header("Location: dashboard.php"); exit; }

if (isset($_POST['descargar'])) {
    csrf_verify();
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $sql  = "-- " . APP_NAME . " — Backup generado el " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- Base de datos: " . DB_NAME . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        $sql .= "-- Tabla: {$table}\n";
        $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
        $sql .= $create['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_NUM);
        if (!empty($rows)) {
            $sql .= "INSERT INTO `{$table}` VALUES\n";
            $inserts = [];
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $inserts[] = '(' . implode(',', $vals) . ')';
            }
            $sql .= implode(",\n", $inserts) . ";\n\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    $filename = 'backup_apex_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

include 'includes/header.php';

$tables_info = $pdo->query("SHOW TABLE STATUS")->fetchAll();
$db_size = array_sum(array_map(fn($t) => (int)$t['Data_length'] + (int)$t['Index_length'], $tables_info));
?>

<div class="page-header">
    <div><h1>Backup de Base de Datos</h1><p>Descarga una copia completa de todos los datos</p></div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card p-4">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb;width:52px;height:52px;font-size:1.3rem"><i class="fa-solid fa-database"></i></div>
                <div>
                    <h6 class="mb-0 fw-700" style="font-weight:700"><?= h(DB_NAME) ?></h6>
                    <div style="font-size:.8rem;color:#64748b">Tamaño aprox.: <?= $db_size > 1024*1024 ? round($db_size/1024/1024,1).'MB' : round($db_size/1024,1).'KB' ?></div>
                </div>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <p class="text-muted small mb-3">Se generará un archivo <code>.sql</code> con toda la estructura y datos actuales. Guárdalo en un lugar seguro.</p>
                <button type="submit" name="descargar" class="btn btn-primary w-100">
                    <i class="fa-solid fa-download me-2"></i>Descargar Backup SQL
                </button>
            </form>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header-custom">
                <h6 class="mb-0 fw-700" style="font-weight:700">Tablas incluidas</h6>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Tabla</th><th>Filas</th><th>Tamaño</th><th>Última actualización</th></tr></thead>
                    <tbody>
                        <?php foreach($tables_info as $t): ?>
                        <tr>
                            <td style="font-family:monospace;font-size:.83rem"><?= htmlspecialchars($t['Name']) ?></td>
                            <td><?= number_format((int)$t['Rows']) ?></td>
                            <td style="font-size:.8rem;color:#64748b"><?= round(((int)$t['Data_length']+(int)$t['Index_length'])/1024,1) ?> KB</td>
                            <td style="font-size:.78rem;color:#94a3b8"><?= $t['Update_time'] ? date('d/m/Y H:i', strtotime($t['Update_time'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
