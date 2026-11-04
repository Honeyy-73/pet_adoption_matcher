<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['admin']);

// OLAP-style report: GROUP BY + HAVING over a 3-table JOIN
$monthly = q("SELECT DATE_FORMAT(a.decided_at, '%Y-%m') AS month,
                     COUNT(*) AS adoptions,
                     SUM(p.species = 'Dog') AS dogs,
                     SUM(p.species = 'Cat') AS cats,
                     ROUND(AVG(a.match_score), 1) AS avg_score
              FROM applications a
              JOIN pets p ON p.pet_id = a.pet_id
              JOIN adopters ad ON ad.adopter_id = a.adopter_id
              WHERE a.status = 'Approved'
              GROUP BY month
              HAVING adoptions > 0
              ORDER BY month DESC")->fetchAll();

// Audit log, newest first
$log = q('SELECT l.log_id, l.created_at, COALESCE(u.username, \'(system)\') AS username, l.action, l.table_name, l.record_id, l.details
          FROM audit_log l LEFT JOIN users u ON u.user_id = l.user_id
          ORDER BY l.log_id DESC LIMIT 100')->fetchAll();

layout_start('Reports & Audit', 'What happened, and who did it?');
?>
<h2>Adoptions per month</h2>
<div class="table-wrap"><table>
  <tr><th>Month</th><th>Adoptions</th><th>Dogs</th><th>Cats</th><th>Avg match score</th></tr>
  <?php foreach ($monthly as $m): ?>
  <tr><td><?= e($m['month']) ?></td><td><?= (int)$m['adoptions'] ?></td><td><?= (int)$m['dogs'] ?></td><td><?= (int)$m['cats'] ?></td><td><?= e($m['avg_score']) ?>%</td></tr>
  <?php endforeach; ?>
  <?php if (!$monthly): ?><tr><td colspan="5" class="muted">No approved adoptions yet.</td></tr><?php endif; ?>
</table></div>

<br>
<h2>Audit log (latest 100)</h2>
<div class="table-wrap"><table>
  <tr><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th><th>Details</th></tr>
  <?php foreach ($log as $l): ?>
  <tr><td><?= e($l['created_at']) ?></td><td><?= e($l['username']) ?></td><td><?= e($l['action']) ?></td>
      <td><?= e($l['table_name']) ?></td><td><?= e($l['record_id'] ?? '-') ?></td><td><?= e($l['details']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php layout_end();
