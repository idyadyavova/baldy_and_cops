<?php
// Выход. Только POST с токеном формы — чужой сайт не сможет разлогинить ссылкой.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

$s = admin_boot();
if (!is_post()) lab_redirect('index.php');
lab_audit((int)$s['user_id'], 'logout', 'выход');
lab_session_end();
lab_redirect('login.php');
