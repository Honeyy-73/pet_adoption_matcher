<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();
$u = user();

if ($u['role'] === 'admin') {
    $stats = [
        ['Users',            q('SELECT COUNT(*) FROM users')->fetchColumn()],
        ['Audit entries',    q('SELECT COUNT(*) FROM audit_log')->fetchColumn()],
        ['Adoptions done',   q("SELECT COUNT(*) FROM applications WHERE status = 'Approved'")->fetchColumn()],
    ];
    $tip = 'check the audit log and make a backup!';
} elseif ($u['role'] === 'staff') {
    $stats = [
        ['Available pets',      q('SELECT COUNT(*) FROM v_available_pets')->fetchColumn()],
        ['Pending applications', q("SELECT COUNT(*) FROM applications WHERE status = 'Pending'")->fetchColumn()],
        ['Adopters',            q('SELECT COUNT(*) FROM adopters')->fetchColumn()],
    ];
    $tip = 'review pending applications today.';
} else {
    $mine  = my_adopter();
    $stats = [
        ['Pets waiting',    q('SELECT COUNT(*) FROM v_available_pets')->fetchColumn()],
        ['My applications', $mine ? q('SELECT COUNT(*) FROM applications WHERE adopter_id = ?', [$mine['adopter_id']])->fetchColumn() : 0],
    ];
    $tip = $mine ? 'try Find matches to meet your pet!' : 'fill in My profile so we can match you!';
}

layout_start('Home', 'Welcome back, ' . $u['full_name'] . '.');
?>
<div class="hero">
  <?= pixel_cat(8) ?>
  <div class="bubble">hello <?= e($u['full_name']) ?>!<br><?= e($tip) ?></div>
</div>
<br>
<div class="cards">
  <?php foreach ($stats as [$label, $value]): ?>
    <div class="card"><div class="stat"><?= (int)$value ?></div><?= e($label) ?></div>
  <?php endforeach; ?>
</div>
<?php layout_end();
