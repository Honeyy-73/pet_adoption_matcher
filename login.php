<?php
require __DIR__ . '/includes/bootstrap.php';
if (user()) go('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        // Prepared query: the username is bound as data, never concatenated into the SQL.
        $row = q('SELECT user_id, username, password_hash, full_name, role, is_active FROM users WHERE username = ?',
                 [$username])->fetch();
        if ($row && (int)$row['is_active'] === 1 && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'user_id'   => (int)$row['user_id'],
                'username'  => $row['username'],
                'full_name' => $row['full_name'],
                'role'      => $row['role'],
            ];
            $_SESSION['login_time'] = time();
            audit('LOGIN', 'users', (int)$row['user_id'], 'User logged in');
            go('dashboard.php');
        }
        $error = 'Wrong username or password, or the account is inactive.';
    }
}

layout_start('Sign in', 'Find your new best friend.', false);
?>
<div class="login-box">
  <div class="hero">
    <?= pixel_cat(8) ?>
    <div class="bubble">welcome!<br>sign in to meet<br>your match :3c</div>
  </div>
  <br>
  <?php if ($error): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="card" autocomplete="off">
    <?= csrf_field() ?>
    <label for="username">Username</label>
    <input id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
    <label for="password">Password</label>
    <input id="password" type="password" name="password" required>
    <p><button type="submit">Login</button></p>
    <p class="muted">No account yet? <a href="register.php">Sign up</a></p>
  </form>
</div>
<?php layout_end();
