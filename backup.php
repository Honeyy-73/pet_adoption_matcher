<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['admin']);

/** Run mysqldump / mysql without a shell. $stdin / $stdout are file paths or null. */
function run_tool(string $tool, array $args, ?string $stdin, ?string $stdout): array {
    $exe = MYSQL_BIN . $tool . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    if (!is_file($exe)) return [1, "Cannot find $exe. Check MYSQL_BIN in config.php."];
    $spec = [
        0 => $stdin  ? ['file', $stdin, 'r']  : ['pipe', 'r'],
        1 => $stdout ? ['file', $stdout, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open(array_merge([$exe], $args), $spec, $pipes);
    if (!is_resource($proc)) return [1, "Could not start $tool."];
    if (!$stdin)  fclose($pipes[0]);
    if (!$stdout) fclose($pipes[1]);
    $err  = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, trim($err)];
}

function conn_args(): array {
    $a = ['--host=' . DB_HOST, '--user=' . DB_USER];
    if (DB_PASS !== '') $a[] = '--password=' . DB_PASS;
    return $a;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'backup') {
        $tmp = tempnam(sys_get_temp_dir(), 'pam');
        [$code, $err] = run_tool('mysqldump',
            array_merge(conn_args(), ['--routines', '--triggers', '--default-character-set=utf8mb4', '--databases', DB_NAME]),
            null, $tmp);
        if ($code === 0 && filesize($tmp) > 0) {
            audit('BACKUP', 'database', null, 'Exported ' . DB_NAME . ' with mysqldump');
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="pet_adoption_matcher_' . date('Ymd_His') . '.sql"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            unlink($tmp);
            exit;
        }
        @unlink($tmp);
        flash('err', 'Backup failed. ' . $err);
        go('backup.php');
    }

    if ($action === 'restore') {
        $f = $_FILES['sqlfile'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            flash('err', 'Please choose a .sql backup file to upload.');
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'sql') {
            flash('err', 'Only .sql files can be restored.');
        } elseif ($f['size'] > 20 * 1024 * 1024) {
            flash('err', 'File is too large (max 20 MB).');
        } else {
            audit('RESTORE', 'database', null, 'Restore started from ' . basename($f['name']));
            [$code, $err] = run_tool('mysql', conn_args(), $f['tmp_name'], null);
            if ($code === 0) {
                // the restored data replaces the current data, so the audit table is the restored one
                flash('ok', 'Restore finished. Data now matches the backup file.');
            } else {
                flash('err', 'Restore failed. ' . $err);
            }
        }
        go('backup.php');
    }
}

layout_start('Backup', 'Save your data. Rescue it later.');
?>
<div class="card">
  <h2>Backup</h2>
  <p>Exports the whole database (tables, views, procedures, functions and triggers) to a <b>.sql</b> file using <b>mysqldump</b>.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button>Download backup</button></form>
</div>
<div class="card">
  <h2>Restore</h2>
  <p>Imports a .sql backup with the <b>mysql</b> client. This <b>replaces current data</b> with the file's data.</p>
  <form method="post" enctype="multipart/form-data" data-confirm="Restoring will overwrite the current data. Continue?">
    <?= csrf_field() ?><input type="hidden" name="action" value="restore">
    <input type="file" name="sqlfile" accept=".sql" required>
    <p><button class="danger">Restore from file</button></p>
  </form>
</div>
<?php layout_end();
