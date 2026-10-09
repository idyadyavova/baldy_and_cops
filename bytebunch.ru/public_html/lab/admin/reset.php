<?php
// Сброс пароля, если он забыт или потерян телефон с кодами.
// Подтверждение — пароль базы из private/lab/config.php: его знает только владелец хостинга.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

admin_boot(false);

$errors = [];
$done = false;
$users = q('SELECT id, login FROM ' . T('users') . ' ORDER BY id')->fetchAll();

if (is_post()) {
    $dbpass = post_str('dbpass', 300);
    $uid = (int)post_str('user', 12);
    $pass = post_str('password', 300);
    $u = null;
    foreach ($users as $x) if ((int)$x['id'] === $uid) $u = $x;
    if (!lab_check_pre_token()) {
        $errors[] = 'Форма устарела. Обновите страницу и повторите.';
    } elseif (($wait = lab_throttled('setup')) > 0) {
        $errors[] = 'Слишком много неверных попыток. Подождите ' . ceil($wait / 60) . ' мин.';
    } elseif ((string)lab_config()['db']['pass'] === '' || !hash_equals((string)lab_config()['db']['pass'], $dbpass)) {
        lab_attempt('setup', '', false);
        lab_audit(null, 'reset_fail', 'неверный пароль базы');
        $errors[] = 'Пароль базы не совпадает с тем, что в config.php.';
    } elseif (!$u) {
        $errors[] = 'Выберите логин.';
    } elseif ($p = lab_password_problem($pass, $u['login'])) {
        $errors[] = $p;
    } else {
        lab_attempt('setup', '', true);
        q('UPDATE ' . T('users') . ' SET pass_hash = ?, pass_changed_at = ?, totp_enabled = 0, totp_secret = NULL WHERE id = ?',
            [lab_hash_password($pass), lab_now(), $u['id']]);
        q('DELETE FROM ' . T('sessions') . ' WHERE user_id = ?', [$u['id']]);
        lab_audit((int)$u['id'], 'reset', 'пароль сброшен через пароль базы, вход по коду выключен');
        $done = true;
    }
}

$pre = lab_pre_token();   // cookie ставим до начала вывода страницы
admin_page_start('Сброс пароля', '', null, true);
?>
<h1>Сброс пароля</h1>
<?php if ($done): ?>
  <div class="notice ok"><p>Пароль изменён, вход по коду выключен, все сессии завершены.</p></div>
  <p class="after"><a class="btn" href="login.php">Войти</a></p>
<?php else: ?>
  <p class="lead">Подтвердите, что вы владелец сайта, паролем базы данных из <code>private/lab/config.php</code>. Вход по коду из приложения при этом выключится — включите его заново в «Безопасности».</p>
  <?php admin_errors($errors); ?>
  <form method="post" class="form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h($pre) ?>">
    <label class="field"><span>Пароль базы данных</span><input type="password" name="dbpass" required autocomplete="off"></label>
    <label class="field"><span>Логин</span><select name="user"><?php foreach ($users as $x): ?><option value="<?= (int)$x['id'] ?>"><?= h($x['login']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Новый пароль <small>не короче 12 символов</small></span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <button class="btn" type="submit">Сменить пароль</button>
  </form>
  <p class="after"><a href="login.php">← Ко входу</a></p>
<?php endif;
admin_page_end();
