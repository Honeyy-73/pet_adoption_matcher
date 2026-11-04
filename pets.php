<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['staff']);

$species = ['Dog', 'Cat'];
$sexes   = ['Male', 'Female'];
$sizes   = ['Small', 'Medium', 'Large'];
$levels  = ['Low', 'Moderate', 'High'];
$errors  = [];
$formTraits = [];
$form = ['pet_id' => 0, 'name' => '', 'species' => 'Dog', 'breed' => '', 'sex' => 'Male', 'age_months' => '',
         'size' => 'Medium', 'energy_level' => 'Moderate', 'is_vaccinated' => 0, 'description' => '',
         'intake_date' => date('Y-m-d')];
$allTraits = q('SELECT trait_id, trait_name FROM traits ORDER BY trait_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $form = [
            'pet_id'        => (int)($_POST['pet_id'] ?? 0),
            'name'          => trim($_POST['name'] ?? ''),
            'species'       => $_POST['species'] ?? '',
            'breed'         => trim($_POST['breed'] ?? ''),
            'sex'           => $_POST['sex'] ?? '',
            'age_months'    => trim($_POST['age_months'] ?? ''),
            'size'          => $_POST['size'] ?? '',
            'energy_level'  => $_POST['energy_level'] ?? '',
            'is_vaccinated' => isset($_POST['is_vaccinated']) ? 1 : 0,
            'description'   => trim($_POST['description'] ?? ''),
            'intake_date'   => $_POST['intake_date'] ?? '',
        ];
        $formTraits = array_map('intval', $_POST['traits'] ?? []);

        if ($form['name'] === '' || mb_strlen($form['name']) > 50)  $errors[] = 'Pet name is required (max 50 characters).';
        if (!in_array($form['species'], $species, true))            $errors[] = 'Choose Dog or Cat.';
        if ($form['breed'] === '' || mb_strlen($form['breed']) > 60) $errors[] = 'Breed is required (max 60 characters).';
        if (!in_array($form['sex'], $sexes, true))                  $errors[] = 'Choose the pet\'s sex.';
        if (!ctype_digit($form['age_months']) || (int)$form['age_months'] > 360) $errors[] = 'Age must be a whole number of months from 0 to 360.';
        if (!in_array($form['size'], $sizes, true))                 $errors[] = 'Choose a size.';
        if (!in_array($form['energy_level'], $levels, true))        $errors[] = 'Choose an energy level.';
        $d = DateTime::createFromFormat('Y-m-d', $form['intake_date']);
        if (!$d || $d->format('Y-m-d') !== $form['intake_date'])    $errors[] = 'Intake date must be a valid date.';

        if (!$errors) {
            try {
                db()->beginTransaction();
                $vals = [$form['name'], $form['species'], $form['breed'], $form['sex'], (int)$form['age_months'],
                         $form['size'], $form['energy_level'], $form['is_vaccinated'], $form['description'], $form['intake_date']];
                if ($form['pet_id']) {
                    q('UPDATE pets SET name=?, species=?, breed=?, sex=?, age_months=?, size=?, energy_level=?, is_vaccinated=?, description=?, intake_date=? WHERE pet_id=?',
                      array_merge($vals, [$form['pet_id']]));
                    $petId = $form['pet_id'];
                    audit('UPDATE', 'pets', $petId, 'Edited pet ' . $form['name']);
                } else {
                    q('INSERT INTO pets (name, species, breed, sex, age_months, size, energy_level, is_vaccinated, description, intake_date) VALUES (?,?,?,?,?,?,?,?,?,?)', $vals);
                    $petId = (int)db()->lastInsertId();
                    audit('CREATE', 'pets', $petId, 'Added pet ' . $form['name']);
                }
                q('DELETE FROM pet_traits WHERE pet_id = ?', [$petId]);
                foreach (array_unique($formTraits) as $tid) {
                    q('INSERT INTO pet_traits (pet_id, trait_id) VALUES (?, ?)', [$petId, $tid]);
                }
                db()->commit();
                flash('ok', 'Pet saved.');
                go('pets.php');
            } catch (PDOException $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $errors[] = 'Database error: the pet could not be saved.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['pet_id'] ?? 0);
        try {
            q('DELETE FROM pets WHERE pet_id = ?', [$id]);     // trigger trg_pets_ad writes the audit entry
            flash('ok', 'Pet deleted.');
        } catch (PDOException $ex) {
            flash('err', 'This pet has adoption applications and cannot be deleted.');
        }
        go('pets.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $row = q('SELECT * FROM pets WHERE pet_id = ?', [(int)$_GET['edit']])->fetch();
    if ($row) {
        $form = $row;
        $formTraits = array_map('intval', q('SELECT trait_id FROM pet_traits WHERE pet_id = ?', [$row['pet_id']])->fetchAll(PDO::FETCH_COLUMN));
    }
}

$kw   = trim($_GET['q'] ?? '');
$like = '%' . $kw . '%';
$pets = q("SELECT p.*, (SELECT GROUP_CONCAT(t.trait_name ORDER BY t.trait_name SEPARATOR ', ')
                         FROM pet_traits pt JOIN traits t ON t.trait_id = pt.trait_id WHERE pt.pet_id = p.pet_id) AS traits
           FROM pets p WHERE p.name LIKE ? OR p.breed LIKE ? OR p.species LIKE ? ORDER BY p.pet_id DESC",
          [$like, $like, $like])->fetchAll();

function opts(array $list, $selected): string {
    $o = '';
    foreach ($list as $v) $o .= '<option ' . ($v === $selected ? 'selected' : '') . '>' . e($v) . '</option>';
    return $o;
}

layout_start('Pets', 'Add, view, update and delete pets.');
?>
<?php if ($errors): ?>
  <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
  <h2><?= $form['pet_id'] ? 'Edit pet #' . (int)$form['pet_id'] : 'Add pet' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="pet_id" value="<?= (int)$form['pet_id'] ?>">
  <div class="form-grid">
    <div><label>Name</label><input name="name" value="<?= e($form['name']) ?>" required></div>
    <div><label>Species</label><select name="species"><?= opts($species, $form['species']) ?></select></div>
    <div><label>Breed</label><input name="breed" value="<?= e($form['breed']) ?>" required></div>
    <div><label>Sex</label><select name="sex"><?= opts($sexes, $form['sex']) ?></select></div>
    <div><label>Age (months)</label><input name="age_months" inputmode="numeric" value="<?= e($form['age_months']) ?>" required></div>
    <div><label>Size</label><select name="size"><?= opts($sizes, $form['size']) ?></select></div>
    <div><label>Energy level</label><select name="energy_level"><?= opts($levels, $form['energy_level']) ?></select></div>
    <div><label>Intake date</label><input type="date" name="intake_date" value="<?= e($form['intake_date']) ?>" required></div>
  </div>
  <label>Description</label>
  <textarea name="description" rows="2" style="width:100%"><?= e($form['description']) ?></textarea>
  <label><input type="checkbox" name="is_vaccinated" <?= $form['is_vaccinated'] ? 'checked' : '' ?>> Vaccinated</label>
  <div class="checks"><label>Traits</label>
    <?php foreach ($allTraits as $t): ?>
      <label><input type="checkbox" name="traits[]" value="<?= (int)$t['trait_id'] ?>" <?= in_array((int)$t['trait_id'], $formTraits, true) ? 'checked' : '' ?>> <?= e($t['trait_name']) ?></label>
    <?php endforeach; ?>
  </div>
  <p><button type="submit">Save</button>
     <?php if ($form['pet_id']): ?><a class="btn alt" href="pets.php">Cancel</a><?php endif; ?></p>
</form>

<form method="get" class="search">
  <input name="q" value="<?= e($kw) ?>" placeholder="search name, breed or species">
  <button>Search</button><?php if ($kw !== ''): ?><a class="btn alt" href="pets.php">Clear</a><?php endif; ?>
</form>

<div class="table-wrap"><table>
  <tr><th>ID</th><th>Name</th><th>Type</th><th>Breed</th><th>Age</th><th>Size</th><th>Energy</th><th>Traits</th><th>Status</th><th></th></tr>
  <?php foreach ($pets as $p): ?>
  <tr>
    <td><?= (int)$p['pet_id'] ?></td><td><?= e($p['name']) ?></td><td><?= e($p['species']) ?></td><td><?= e($p['breed']) ?></td>
    <td><?= (int)$p['age_months'] ?> mo</td><td><?= e($p['size']) ?></td><td><?= e($p['energy_level']) ?></td>
    <td><?= e($p['traits'] ?? '-') ?></td>
    <td><span class="tag <?= e($p['status']) ?>"><?= e($p['status']) ?></span></td>
    <td class="row-actions">
      <a class="btn small" href="pets.php?edit=<?= (int)$p['pet_id'] ?>">Edit</a>
      <form method="post" data-confirm="Are you sure you want to delete <?= e($p['name']) ?>?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input type="hidden" name="pet_id" value="<?= (int)$p['pet_id'] ?>">
        <button class="small danger">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$pets): ?><tr><td colspan="10" class="muted">No pets found.</td></tr><?php endif; ?>
</table></div>
<?php layout_end();
