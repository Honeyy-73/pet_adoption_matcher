<?php
// Public sign up. Self-registered accounts are ALWAYS role "visitor".
// Staff and Admin accounts can only be created by an Admin on the Users page.
require __DIR__ . '/includes/bootstrap.php';
if (user()) go('dashboard.php');

$errors = [];
$old = ['full_name' => '', 'username' => ''];
$reserved = ['admin', 'administrator', 'staff', 'visitor', 'root', 'system', 'superadmin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['username']  = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';          // never trimmed: spaces are rejected instead
    $confirm  = $_POST['confirm'] ?? '';

    if ($old['full_name'] === '' || mb_strlen($old['full_name']) > 100) $errors[] = 'Full name is required (max 100 characters).';
    if ($m = check_username($old['username']))                          $errors[] = $m;
    elseif (in_array(strtolower($old['username']), $reserved, true))    $errors[] = 'That username is reserved. Please pick another one.';
    if ($m = check_password($password))                                 $errors[] = $m;
    elseif ($password !== $confirm)                                     $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            q("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, 'visitor')",
              [$old['username'], password_hash($password, PASSWORD_DEFAULT), $old['full_name']]);
            $newId = (int)db()->lastInsertId();
            q('INSERT INTO audit_log (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)',
              [$newId, 'SIGNUP', 'users', $newId, 'New visitor account ' . $old['username']]);
            flash('ok', 'Account created! You can sign in now.');
            go('login.php');
        } catch (PDOException $ex) {
            $errors[] = ($ex->errorInfo[1] ?? 0) == 1062 ? 'That username is already taken.'
                      : 'Database error: the account could not be created.';
        }
    }
}

layout_start('Sign up', 'Make an account to meet your match.', false);
?>
<div class="login-box">
  <?php if ($errors): ?>
    <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
  <?php endif; ?>
  <form method="post" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <label for="full_name">Full name</label>
    <input id="full_name" name="full_name" maxlength="100" value="<?= e($old['full_name']) ?>" required autofocus>
    <label for="username">Username</label>
    <input id="username" name="username" maxlength="20" value="<?= e($old['username']) ?>" required>
    <div class="muted">4-20 characters, starts with a letter, only letters, numbers and _ (no spaces).</div>
    <label for="password">Password</label>
    <input id="password" type="password" name="password" maxlength="64" required>
    <div class="muted">8-64 characters, at least 1 letter and 1 number, no spaces.</div>
    <label for="confirm">Confirm password</label>
    <input id="confirm" type="password" name="confirm" maxlength="64" required>
    <p><button type="submit">Create account</button></p>
    <p class="muted">Already have an account? <a href="login.php">Sign in</a></p>
  </form>
</div>
<?php layout_end();
