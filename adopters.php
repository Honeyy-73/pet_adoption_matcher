<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['staff']);

$housing = ['Apartment', 'House'];
$levels  = ['Low', 'Moderate', 'High'];
$prefs   = ['Dog', 'Cat', 'Any'];
$errors  = [];
$form = ['adopter_id' => 0, 'first_name' => '', 'last_name' => '', 'email' => '', 'contact_no' => '',
         'housing_type' => 'Apartment', 'has_kids' => 0, 'has_other_pets' => 0,
         'activity_level' => 'Moderate', 'preferred_species' => 'Any'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $form = [
            'adopter_id'        => (int)($_POST['adopter_id'] ?? 0),
            'first_name'        => trim($_POST['first_name'] ?? ''),
            'last_name'         => trim($_POST['last_name'] ?? ''),
            'email'             => trim($_POST['email'] ?? ''),
            'contact_no'        => trim($_POST['contact_no'] ?? ''),
            'housing_type'      => $_POST['housing_type'] ?? '',
            'has_kids'          => isset($_POST['has_kids']) ? 1 : 0,
            'has_other_pets'    => isset($_POST['has_other_pets']) ? 1 : 0,
            'activity_level'    => $_POST['activity_level'] ?? '',
            'preferred_species' => $_POST['preferred_species'] ?? '',
        ];

        if ($form['first_name'] === '' || mb_strlen($form['first_name']) > 50) $errors[] = 'First name is required (max 50 characters).';
        if ($form['last_name'] === ''  || mb_strlen($form['last_name']) > 50)  $errors[] = 'Last name is required (max 50 characters).';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL))                $errors[] = 'Email must look like name@example.com.';
        if (!preg_match('/^(09|\+639)\d{9}$/', $form['contact_no']))           $errors[] = 'Contact number must look like 09171234567.';
        if (!in_array($form['housing_type'], $housing, true))                  $errors[] = 'Choose a housing type.';
        if (!in_array($form['activity_level'], $levels, true))                 $errors[] = 'Choose an activity level.';
        if (!in_array($form['preferred_species'], $prefs, true))               $errors[] = 'Choose a preferred species.';

        if (!$errors) {
            try {
                // contact_no is encrypted at rest with AES (key kept in config.php)
                if ($form['adopter_id']) {
                    q('UPDATE adopters SET first_name=?, last_name=?, email=?, contact_no=AES_ENCRYPT(?, ?), housing_type=?, has_kids=?, has_other_pets=?, activity_level=?, preferred_species=? WHERE adopter_id=?',
                      [$form['first_name'], $form['last_name'], $form['email'], $form['contact_no'], ENC_KEY, $form['housing_type'],
                       $form['has_kids'], $form['has_other_pets'], $form['activity_level'], $form['preferred_species'], $form['adopter_id']]);
                    audit('UPDATE', 'adopters', $form['adopter_id'], 'Edited adopter ' . $form['first_name'] . ' ' . $form['last_name']);
                } else {
                    q('INSERT INTO adopters (first_name, last_name, email, contact_no, housing_type, has_kids, has_other_pets, activity_level, preferred_species) VALUES (?,?,?,AES_ENCRYPT(?, ?),?,?,?,?,?)',
                      [$form['first_name'], $form['last_name'], $form['email'], $form['contact_no'], ENC_KEY, $form['housing_type'],
                       $form['has_kids'], $form['has_other_pets'], $form['activity_level'], $form['preferred_species']]);
                    audit('CREATE', 'adopters', (int)db()->lastInsertId(), 'Added adopter ' . $form['first_name'] . ' ' . $form['last_name']);
                }
                flash('ok', 'Adopter saved.');
                go('adopters.php');
            } catch (PDOException $ex) {
                $errors[] = ($ex->errorInfo[1] ?? 0) == 1062 ? 'That email is already registered.'
                          : 'Database error: the adopter could not be saved.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['adopter_id'] ?? 0);
        try {
            q('DELETE FROM adopters WHERE adopter_id = ?', [$id]);   // trigger trg_adopters_ad writes the audit entry
            flash('ok', 'Adopter deleted.');
        } catch (PDOException $ex) {
            flash('err', 'This adopter has adoption applications and cannot be deleted.');
        }
        go('adopters.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $row = q('SELECT adopter_id, first_name, last_name, email, CAST(AES_DECRYPT(contact_no, ?) AS CHAR) AS contact_no,
                     housing_type, has_kids, has_other_pets, activity_level, preferred_species
              FROM adopters WHERE adopter_id = ?', [ENC_KEY, (int)$_GET['edit']])->fetch();
    if ($row) $form = $row;
}

$kw   = trim($_GET['q'] ?? '');
$like = '%' . $kw . '%';
$rows = q('SELECT adopter_id, first_name, last_name, email, CAST(AES_DECRYPT(contact_no, ?) AS CHAR) AS contact,
                  housing_type, has_kids, has_other_pets, activity_level, preferred_species
           FROM adopters WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? ORDER BY adopter_id DESC',
          [ENC_KEY, $like, $like, $like])->fetchAll();

function opts(array $list, $selected): string {
    $o = '';
    foreach ($list as $v) $o .= '<option ' . ($v === $selected ? 'selected' : '') . '>' . e($v) . '</option>';
    return $o;
}

layout_start('Adopters', 'Add, view, update and delete adopters.');
?>
<?php if ($errors): ?>
  <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
  <h2><?= $form['adopter_id'] ? 'Edit adopter #' . (int)$form['adopter_id'] : 'Add adopter' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="adopter_id" value="<?= (int)$form['adopter_id'] ?>">
  <div class="form-grid">
    <div><label>First name</label><input name="first_name" value="<?= e($form['first_name']) ?>" required></div>
    <div><label>Last name</label><input name="last_name" value="<?= e($form['last_name']) ?>" required></div>
    <div><label>Email</label><input type="email" name="email" value="<?= e($form['email']) ?>" required></div>
    <div><label>Contact no. (stored encrypted)</label><input name="contact_no" value="<?= e($form['contact_no']) ?>" placeholder="09171234567" required></div>
    <div><label>Housing</label><select name="housing_type"><?= opts($housing, $form['housing_type']) ?></select></div>
    <div><label>Activity level</label><select name="activity_level"><?= opts($levels, $form['activity_level']) ?></select></div>
    <div><label>Preferred species</label><select name="preferred_species"><?= opts($prefs, $form['preferred_species']) ?></select></div>
  </div>
  <div class="checks">
    <label><input type="checkbox" name="has_kids" <?= $form['has_kids'] ? 'checked' : '' ?>> Has kids at home</label>
    <label><input type="checkbox" name="has_other_pets" <?= $form['has_other_pets'] ? 'checked' : '' ?>> Has other pets</label>
  </div>
  <p><button type="submit">Save</button>
     <?php if ($form['adopter_id']): ?><a class="btn alt" href="adopters.php">Cancel</a><?php endif; ?></p>
</form>

<form method="get" class="search">
  <input name="q" value="<?= e($kw) ?>" placeholder="search name or email">
  <button>Search</button><?php if ($kw !== ''): ?><a class="btn alt" href="adopters.php">Clear</a><?php endif; ?>
</form>

<div class="table-wrap"><table>
  <tr><th>ID</th><th>Name</th><th>Email</th><th>Contact</th><th>Housing</th><th>Kids</th><th>Pets</th><th>Activity</th><th>Wants</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= (int)$r['adopter_id'] ?></td><td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td><td><?= e($r['email']) ?></td>
    <td><?= e($r['contact']) ?></td><td><?= e($r['housing_type']) ?></td>
    <td><?= $r['has_kids'] ? 'Yes' : 'No' ?></td><td><?= $r['has_other_pets'] ? 'Yes' : 'No' ?></td>
    <td><?= e($r['activity_level']) ?></td><td><?= e($r['preferred_species']) ?></td>
    <td class="row-actions">
      <a class="btn small" href="adopters.php?edit=<?= (int)$r['adopter_id'] ?>">Edit</a>
      <form method="post" data-confirm="Are you sure you want to delete <?= e($r['first_name']) ?>?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input type="hidden" name="adopter_id" value="<?= (int)$r['adopter_id'] ?>">
        <button class="small danger">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="10" class="muted">No adopters found.</td></tr><?php endif; ?>
</table></div>
<?php layout_end();
