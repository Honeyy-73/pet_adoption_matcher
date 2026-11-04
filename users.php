<?php
require __DIR__ . '/includes/bootstrap.php';
require_role(['admin']);

$roles  = ['admin' => 'Admin', 'staff' => 'Staff', 'visitor' => 'Visitor'];
$errors = [];
$form   = ['user_id' => 0, 'username' => '', 'full_name' => '', 'role' => 'visitor', 'supervisor_id' => '', 'is_active' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['user_id'] ?? 0);
        $username  = trim($_POST['username'] ?? '');
        $fullName  = trim($_POST['full_name'] ?? '');
        $role      = $_POST['role'] ?? '';
        $super     = (int)($_POST['supervisor_id'] ?? 0) ?: null;
        $active    = isset($_POST['is_active']) ? 1 : 0;
        $password  = $_POST['password'] ?? '';
        $form = ['user_id' => $id, 'username' => $username, 'full_name' => $fullName, 'role' => $role,
                 'supervisor_id' => $super, 'is_active' => $active];

        if ($m = check_username($username))                   $errors[] = $m;
        if ($fullName === '' || mb_strlen($fullName) > 100)   $errors[] = 'Full name is required (max 100 characters).';
        if (!isset($roles[$role]))                            $errors[] = 'Please choose a valid role.';
        if ($id === 0 && $password === '')                    $errors[] = 'Password is required for a new user.';
        if ($password !== '' && ($m = check_password($password))) $errors[] = $m;
        if ($super !== null && $super === $id)                $errors[] = 'A user cannot supervise themselves.';
        if ($id === user()['user_id'] && ($role !== 'admin' || !$active))
                                                              $errors[] = 'You cannot demote or deactivate your own account.';

        if (!$errors) {
            try {
                if ($id) {
                    if ($password !== '') {
                        q('UPDATE users SET username=?, full_name=?, role=?, supervisor_id=?, is_active=?, password_hash=? WHERE user_id=?',
                          [$username, $fullName, $role, $super, $active, password_hash($password, PASSWORD_DEFAULT), $id]);
                    } else {
                        q('UPDATE users SET username=?, full_name=?, role=?, supervisor_id=?, is_active=? WHERE user_id=?',
                          [$username, $fullName, $role, $super, $active, $id]);
                    }
                    audit('UPDATE', 'users', $id, "Edited user $username");
                } else {
                    q('INSERT INTO users (username, password_hash, full_name, role, supervisor_id, is_active) VALUES (?,?,?,?,?,?)',
                      [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role, $super, $active]);
                    audit('CREATE', 'users', (int)db()->lastInsertId(), "Created user $username");
                }
                flash('ok', 'User saved.');
                go('users.php');
            } catch (PDOException $ex) {
                $errors[] = ($ex->errorInfo[1] ?? 0) == 1062 ? 'That username is already taken.'
                          : 'Database error: the user could not be saved.';
            }
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id === user()['user_id']) {
            flash('err', 'You cannot delete your own account.');
        } else {
            try {
                q('DELETE FROM users WHERE user_id = ?', [$id]);
                audit('DELETE', 'users', $id, "Deleted user #$id");
                flash('ok', 'User deleted.');
            } catch (PDOException $ex) {
                flash('err', 'This user has adoption records and cannot be deleted. Deactivate the account instead.');
            }
        }
        go('users.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $row = q('SELECT user_id, username, full_name, role, supervisor_id, is_active FROM users WHERE user_id = ?', [(int)$_GET['edit']])->fetch();
    if ($row) $form = $row;
}

$users = q('SELECT u.user_id, u.username, u.full_name, u.role, u.is_active, s.full_name AS supervisor
            FROM users u LEFT JOIN users s ON s.user_id = u.supervisor_id ORDER BY u.user_id')->fetchAll();
$supervisors = q("SELECT user_id, full_name FROM users WHERE role IN ('admin','staff') ORDER BY full_name")->fetchAll();

layout_start('Users', 'Manage who can enter.');
?>
<?php if ($errors): ?>
  <div class="msg err"><ul><?php foreach ($errors as $m) echo '<li>' . e($m) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="post" class="card">
  <h2><?= $form['user_id'] ? 'Edit user #' . (int)$form['user_id'] : 'Add user' ?></h2>
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="user_id" value="<?= (int)$form['user_id'] ?>">
  <div class="form-grid">
    <div><label>Username</label><input name="username" maxlength="20" value="<?= e($form['username']) ?>" required></div>
    <div><label>Full name</label><input name="full_name" value="<?= e($form['full_name']) ?>" required></div>
    <div><label>Role</label>
      <select name="role"><?php foreach ($roles as $k => $v): ?>
        <option value="<?= e($k) ?>" <?= $form['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?></select></div>
    <div><label>Supervisor</label>
      <select name="supervisor_id"><option value="">(none)</option>
        <?php foreach ($supervisors as $s): ?>
          <option value="<?= (int)$s['user_id'] ?>" <?= (int)$form['supervisor_id'] === (int)$s['user_id'] ? 'selected' : '' ?>><?= e($s['full_name']) ?></option>
        <?php endforeach; ?></select></div>
    <div><label>Password <?= $form['user_id'] ? '(leave blank to keep)' : '' ?></label><input type="password" name="password" maxlength="64" autocomplete="new-password"></div>
    <div><label>&nbsp;</label><label><input type="checkbox" name="is_active" <?= $form['is_active'] ? 'checked' : '' ?>> Active account</label></div>
  </div>
  <p><button type="submit">Save</button>
     <?php if ($form['user_id']): ?><a class="btn alt" href="users.php">Cancel</a><?php endif; ?></p>
</form>

<div class="table-wrap"><table>
  <tr><th>ID</th><th>Username</th><th>Name</th><th>Role</th><th>Supervisor</th><th>Active</th><th></th></tr>
  <?php foreach ($users as $r): ?>
  <tr>
    <td><?= (int)$r['user_id'] ?></td><td><?= e($r['username']) ?></td><td><?= e($r['full_name']) ?></td>
    <td><?= e($roles[$r['role']]) ?></td><td><?= e($r['supervisor'] ?? '-') ?></td>
    <td><?= $r['is_active'] ? 'Yes' : 'No' ?></td>
    <td class="row-actions">
      <a class="btn small" href="users.php?edit=<?= (int)$r['user_id'] ?>">Edit</a>
      <form method="post" data-confirm="Are you sure you want to delete user <?= e($r['username']) ?>?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
        <button class="small danger">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php layout_end();
