<?php
// Shared helpers: database, session, login checks, CSRF, flash messages, audit log.
require_once __DIR__ . '/../config.php';
session_start();

/** One PDO connection per request. Prepared statements are real (not emulated). */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // Tell the audit triggers who is acting in this connection.
        if (!empty($_SESSION['user']['user_id'])) {
            $st = $pdo->prepare('SET @current_user_id = ?');
            $st->execute([$_SESSION['user']['user_id']]);
        }
    }
    return $pdo;
}

/** Run a prepared query. ALL user input must go through $params, never string concatenation. */
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** Call a stored procedure that has OUT params @p_code and @p_msg as its last two arguments. */
function call_proc(string $sql, array $params): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    $st->closeCursor();
    return db()->query('SELECT @p_code AS code, @p_msg AS msg')->fetch();
}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function go(string $to): void { header('Location: ' . $to); exit; }

// ---- session / roles ----
function user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void { if (!user()) go('login.php'); }
function require_role(array $roles): void {
    require_login();
    if (!in_array(user()['role'], $roles, true)) {
        http_response_code(403);
        layout_start('Access denied', 'Access denied.');
        echo '<div class="card"><h2>403 - Not allowed</h2><p>Your role (' . e(user()['role']) .
             ') cannot open this page.</p><p><a class="btn" href="dashboard.php">Back to home</a></p></div>';
        layout_end();
        exit;
    }
}

// ---- input rules shared by sign up and the Users page ----
/** Username: 4-20 chars, starts with a letter, then letters / numbers / underscore. No spaces. */
function check_username(string $u): ?string {
    if (preg_match('/\s/', $u))                       return 'Username cannot contain spaces.';
    if (strlen($u) < 4 || strlen($u) > 20)             return 'Username must be 4 to 20 characters long.';
    if (!preg_match('/^[A-Za-z]/', $u))                return 'Username must start with a letter.';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $u))          return 'Username can only use letters, numbers and underscore (_).';
    return null;
}
/** Password: 8-64 chars, NO spaces, at least one letter and one number. */
function check_password(string $p): ?string {
    if (preg_match('/\s/', $p))                       return 'Password cannot contain spaces.';
    if (strlen($p) < 8 || strlen($p) > 64)             return 'Password must be 8 to 64 characters long.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Password must have at least one letter and one number.';
    return null;
}

/** The adopter profile that belongs to the logged-in Visitor (or null if not created yet). */
function my_adopter(): ?array {
    if (!user()) return null;
    $row = q('SELECT adopter_id, first_name, last_name, email, CAST(AES_DECRYPT(contact_no, ?) AS CHAR) AS contact_no,
                     housing_type, has_kids, has_other_pets, activity_level, preferred_species
              FROM adopters WHERE user_id = ?', [ENC_KEY, user()['user_id']])->fetch();
    return $row ?: null;
}

// ---- CSRF ----
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid form token. Go back, refresh the page and try again.');
    }
}

// ---- flash messages ----
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flash_render(): string {
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $out .= '<div class="msg ' . e($type) . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

// ---- audit log (who did what and when) ----
function audit(string $action, string $table, ?int $recordId, string $details): void {
    q('INSERT INTO audit_log (user_id, action, table_name, record_id, details) VALUES (?, ?, ?, ?, ?)',
      [user()['user_id'] ?? null, $action, $table, $recordId, $details]);
}

require_once __DIR__ . '/layout.php';
