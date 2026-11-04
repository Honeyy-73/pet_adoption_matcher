<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'submit') {
        $adopterId = (int)($_POST['adopter_id'] ?? 0);
        $petId     = (int)($_POST['pet_id'] ?? 0);
        if ($adopterId <= 0 || $petId <= 0) {
            flash('err', 'Please choose both an adopter and a pet.');
        } else {
            // Transaction 1 runs inside the stored procedure (lock pet row, insert, set Pending, COMMIT/ROLLBACK)
            $res = call_proc('CALL sp_submit_application(?, ?, ?, @p_code, @p_msg)', [$adopterId, $petId, user()['user_id']]);
            flash((int)$res['code'] === 0 ? 'ok' : 'err', $res['msg']);
        }
    }

    if ($action === 'decide') {
        $appId    = (int)($_POST['application_id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        // Transaction 2 runs inside the stored procedure (approve/reject, update pet, COMMIT/ROLLBACK)
        $res = call_proc('CALL sp_decide_application(?, ?, ?, @p_code, @p_msg)', [$appId, $decision, user()['user_id']]);
        flash((int)$res['code'] === 0 ? 'ok' : 'err', $res['msg']);
    }
    go('applications.php');
}

$adopters = q("SELECT adopter_id, CONCAT(first_name, ' ', last_name) AS name FROM adopters ORDER BY first_name")->fetchAll();
$pets     = q('SELECT pet_id, name, species, breed FROM v_available_pets ORDER BY name')->fetchAll();
$apps     = q('SELECT * FROM v_application_details ORDER BY application_id DESC')->fetchAll();

layout_start('Adoption', 'Submit and decide adoption applications.');
?>
<form method="post" class="card">
  <h2>New application</h2>
  <?= csrf_field() ?><input type="hidden" name="action" value="submit">
  <div class="form-grid">
    <div><label>Adopter</label>
      <select name="adopter_id" required><option value="">- choose -</option>
        <?php foreach ($adopters as $a): ?><option value="<?= (int)$a['adopter_id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Available pet</label>
      <select name="pet_id" required><option value="">- choose -</option>
        <?php foreach ($pets as $p): ?><option value="<?= (int)$p['pet_id'] ?>"><?= e($p['name'] . ' (' . $p['species'] . ', ' . $p['breed'] . ')') ?></option><?php endforeach; ?>
      </select></div>
  </div>
  <p><button type="submit">Submit application</button></p>
</form>

<div class="table-wrap"><table>
  <tr><th>#</th><th>Adopter</th><th>Pet</th><th>Match</th><th>Status</th><th>Applied</th><th>Decided by</th><th></th></tr>
  <?php foreach ($apps as $a): ?>
  <tr>
    <td><?= (int)$a['application_id'] ?></td><td><?= e($a['adopter_name']) ?></td>
    <td><?= e($a['pet_name']) ?> <span class="muted">(<?= e($a['species']) ?>)</span></td>
    <td><span class="bar"><span style="width:<?= (int)$a['match_score'] ?>%"></span></span> <?= (int)$a['match_score'] ?>%</td>
    <td><span class="tag <?= e($a['status']) ?>"><?= e($a['status']) ?></span></td>
    <td><?= e($a['applied_at']) ?></td><td><?= e($a['decided_by_name'] ?? '-') ?></td>
    <td class="row-actions">
      <?php if ($a['status'] === 'Pending'): ?>
        <?php foreach (['Approved' => ['Approve', ''], 'Rejected' => ['Reject', 'danger']] as $dec => [$label, $cls]): ?>
        <form method="post" data-confirm="<?= e($label) ?> application #<?= (int)$a['application_id'] ?>? This cannot be undone.">
          <?= csrf_field() ?><input type="hidden" name="action" value="decide">
          <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
          <input type="hidden" name="decision" value="<?= e($dec) ?>">
          <button class="small <?= e($cls) ?>"><?= e($label) ?></button>
        </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$apps): ?><tr><td colspan="8" class="muted">No applications yet.</td></tr><?php endif; ?>
</table></div>
<?php layout_end();
