<?php
require __DIR__ . '/includes/bootstrap.php';
if (user()) audit('LOGOUT', 'users', user()['user_id'], 'User logged out');
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
