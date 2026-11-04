<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['visitor']);

$me   = my_adopter();
$apps = $me ? q('SELECT * FROM v_application_details WHERE adopter_id = ? ORDER BY application_id DESC', [$me['adopter_id']])->fetchAll() : [];

layout_start('My applications', 'Your adoption journey.');
?>
<?php if (!$me): ?>
  <div class="msg err">You have no profile yet. <a href="profile.php">Create My profile</a>, then apply to a pet.</div>
<?php endif; ?>
<div class="table-wrap"><table>
  <tr><th>#</th><th>Pet</th><th>Match</th><th>Status</th><th>Applied</th><th>Decided</th></tr>
  <?php foreach ($apps as $a): ?>
  <tr>
    <td><?= (int)$a['application_id'] ?></td>
    <td><?= e($a['pet_name']) ?> <span class="muted">(<?= e($a['species']) ?>)</span></td>
    <td><span class="bar"><span style="width:<?= (int)$a['match_score'] ?>%"></span></span> <?= (int)$a['match_score'] ?>%</td>
    <td><span class="tag <?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
    <td><?= e($a['applied_at']) ?></td><td><?= e($a['decided_at'] ?? '-') ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$apps): ?><tr><td colspan="6" class="muted">No applications yet. Browse pets and press "Apply to adopt".</td></tr><?php endif; ?>
</table></div>
<p class="muted">Pending = waiting for Staff. Approved = congratulations, the pet is yours! Rejected = the pet is available to others again.</p>
<?php layout_end();
