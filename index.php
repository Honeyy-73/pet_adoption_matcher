<?php
require __DIR__ . '/includes/bootstrap.php';
go(user() ? 'dashboard.php' : 'login.php');
