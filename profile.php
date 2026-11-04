<?php
// A Visitor's own adopter profile. It is used for matching and for adoption applications.
require __DIR__ . '/includes/bootstrap.php';
require_role(['visitor']);

$housing = ['Apartment', 'House'];
$levels  = ['Low', 'Moderate', 'High'];
$prefs   = ['Dog', 'Cat', 'Any'];
$errors  = [];
$existing = my_adopter();
$form = $existing ?? ['first_name' => '', 'last_name' => '', 'email' => '', 'contact_no' => '',
                      'housing_type' => 'Apartment', 'has_kids' => 0, 'has_other_pets' => 0,
                      'activity_level' => 'Moderate', 'preferred_species' => 'Any'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $form = [
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
            $vals = [$form['first_name'], $form['last_name'], $form['email'], $form['contact_no'], ENC_KEY,
                     $form['housing_type'], $form['has_kids'], $form['has_other_pets'], $form['activity_level'], $form['preferred_species']];
            if ($existing) {
                q('UPDATE adopters SET first_name=?, last_name=?, email=?, contact_no=AES_ENCRYPT(?, ?), housing_type=?, has_kids=?, has_other_pets=?, activity_level=?, preferred_species=? WHERE user_id=?',
                  array_merge($vals, [user()['user_id']]));
                audit('UPDATE', 'adopters', (int)$existing['adopter_id'], 'Visitor updated own profile');
            } else {
                q('INSERT INTO adopters (first_name, last_name, email, contact_no, housing_type, has_kids, has_other_pets, activity_level, preferred_species, user_id) VALUES (?,?,?,AES_ENCRYPT(?, ?),?,?,?,?,?,?)',
                  array_merge($vals, [user()['user_id']]));
                audit('CREATE', 'adopters', (int)db()->lastInsertId(), 'Visitor created own profile');
            }
            flash('ok', 'Profile saved. Now you can find matches and adopt!');
            go('profile.php');
        } catch (PDOException $ex) {
            $errors[] = ($ex->errorInfo[1] ?? 0) == 1062 ? 'That email is already registered to another profile.'
                      : 'Database error: the profile could not be saved.';
        }
    }
}

function opts(array $list, $selected): string {
    $o = '';
    foreach ($list as $v) $o .= '<option ' . ($v === $selected ? 'selected' : '') . '>' . e($v) . '</option>';
    return $o;
}

layout_start('My profile', 'Tell us about your home.');
?>
<?php if (!$existing): ?><div class="msg">Fill this in once. We use it to match you with pets and to send your adoption applications.</div><?php endif; ?>
<?php if ($errors): ?>
  <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
<?php endif; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="form-grid">
    <div><label>First name</label><input name="first_name" value="<?= e($form['first_name']) ?>" required></div>
    <div><label>Last name</label><input name="last_name" value="<?= e($form['last_name']) ?>" required></div>
    <div><label>Email</label><input type="email" name="email" value="<?= e($form['email']) ?>" required></div>
    <div><label>Contact no. (stored encrypted)</label><input name="contact_no" value="<?= e($form['contact_no']) ?>" placeholder="09171234567" required></div>
    <div><label>Where do you live?</label><select name="housing_type"><?= opts($housing, $form['housing_type']) ?></select></div>
    <div><label>How active are you?</label><select name="activity_level"><?= opts($levels, $form['activity_level']) ?></select></div>
    <div><label>Preferred pet</label><select name="preferred_species"><?= opts($prefs, $form['preferred_species']) ?></select></div>
  </div>
  <div class="checks">
    <label><input type="checkbox" name="has_kids" <?= $form['has_kids'] ? 'checked' : '' ?>> I have kids at home</label>
    <label><input type="checkbox" name="has_other_pets" <?= $form['has_other_pets'] ? 'checked' : '' ?>> I have other pets</label>
  </div>
  <p><button type="submit">Save profile</button></p>
</form>
<?php layout_end();
