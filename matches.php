<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$housing = ['Apartment', 'House'];
$levels  = ['Low', 'Moderate', 'High'];
$prefs   = ['Dog', 'Cat', 'Any'];
$in      = ['housing' => 'Apartment', 'kids' => 0, 'others' => 0, 'activity' => 'Moderate', 'species' => 'Any'];
$errors  = [];
$results = null;
$isVisitor = user()['role'] === 'visitor';
if ($isVisitor && $_SERVER['REQUEST_METHOD'] === 'GET' && ($mine = my_adopter())) {
    $in = ['housing' => $mine['housing_type'], 'kids' => (int)$mine['has_kids'], 'others' => (int)$mine['has_other_pets'],
           'activity' => $mine['activity_level'], 'species' => $mine['preferred_species']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = [
        'housing'  => $_POST['housing'] ?? '',
        'kids'     => isset($_POST['kids']) ? 1 : 0,
        'others'   => isset($_POST['others']) ? 1 : 0,
        'activity' => $_POST['activity'] ?? '',
        'species'  => $_POST['species'] ?? '',
    ];
    if (!in_array($in['housing'], $housing, true))  $errors[] = 'Choose your housing type.';
    if (!in_array($in['activity'], $levels, true))  $errors[] = 'Choose your activity level.';
    if (!in_array($in['species'], $prefs, true))    $errors[] = 'Choose a preferred species.';

    if (!$errors) {
        // sp_rank_matches uses fn_match_score() and RANK() OVER (ORDER BY score DESC)
        $st = db()->prepare('CALL sp_rank_matches(?, ?, ?, ?, ?)');
        $st->execute([$in['housing'], $in['kids'], $in['others'], $in['activity'], $in['species']]);
        $results = $st->fetchAll();
        $st->closeCursor();
    }
}

function opts(array $list, $selected): string {
    $o = '';
    foreach ($list as $v) $o .= '<option ' . ($v === $selected ? 'selected' : '') . '>' . e($v) . '</option>';
    return $o;
}

layout_start('Find matches', 'Tell us about you, we rank the pets.');
?>
<?php if ($errors): ?>
  <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
<?php endif; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="form-grid">
    <div><label>Where do you live?</label><select name="housing"><?= opts($housing, $in['housing']) ?></select></div>
    <div><label>How active are you?</label><select name="activity"><?= opts($levels, $in['activity']) ?></select></div>
    <div><label>Preferred pet</label><select name="species"><?= opts($prefs, $in['species']) ?></select></div>
  </div>
  <div class="checks">
    <label><input type="checkbox" name="kids" <?= $in['kids'] ? 'checked' : '' ?>> I have kids at home</label>
    <label><input type="checkbox" name="others" <?= $in['others'] ? 'checked' : '' ?>> I have other pets</label>
  </div>
  <p><button type="submit">Find my match</button></p>
</form>

<?php if ($results !== null): ?>
<div class="table-wrap"><table>
  <tr><th>Rank</th><th>Pet</th><th>Type</th><th>Breed</th><th>Age</th><th>Size</th><th>Energy</th><th>Match</th><?php if ($isVisitor): ?><th></th><?php endif; ?></tr>
  <?php foreach ($results as $r): ?>
  <tr>
    <td>#<?= (int)$r['rank_no'] ?></td><td><?= e($r['name']) ?></td><td><?= e($r['species']) ?></td><td><?= e($r['breed']) ?></td>
    <td><?= (int)$r['age_months'] ?> mo</td><td><?= e($r['size']) ?></td><td><?= e($r['energy_level']) ?></td>
    <td><span class="bar"><span style="width:<?= (int)$r['score'] ?>%"></span></span> <?= (int)$r['score'] ?>%</td>
    <?php if ($isVisitor): ?>
    <td><form method="post" action="apply.php" data-confirm="Apply to adopt <?= e($r['name']) ?>?">
      <?= csrf_field() ?><input type="hidden" name="pet_id" value="<?= (int)$r['pet_id'] ?>">
      <button class="small">Apply</button></form></td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  <?php if (!$results): ?><tr><td colspan="9" class="muted">No available pets right now.</td></tr><?php endif; ?>
</table></div>
<?php endif; ?>
<?php layout_end();
