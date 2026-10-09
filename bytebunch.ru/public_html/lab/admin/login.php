<?php
// Вход: логин и пароль, затем — если включено — шестизначный код из приложения.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

admin_boot(false);

/** Куда вернуть после входа: только на страницы самой панели. */
function login_next(): string
{
    $n = get_str('next');
    $admin = lab_base() . 'admin/';
    if ($n !== '' && strpos($n, $admin) === 0 && strpos($n, '//') === false && strpos($n, '\\') === false) return $n;
    return 'index.php';
}

if (lab_session()) lab_redirect(login_next());

$pending = lab_session(true);   // пароль уже верный, ждём код
$errors = [];
$login = '';

if (is_post()) {
    $action = post_str('action', 10);

    if ($action === 'login') {
        $login = trim(post_str('login', 64));
        $pass = post_str('password', 300);
        if (!lab_check_pre_token()) {
            $errors[] = 'Форма устарела. Обновите страницу и повторите.';
        } elseif (($wait = lab_throttled('login', strtolower($login))) > 0) {
            $errors[] = 'Слишком много неверных попыток. Попробуйте через ' . ceil($wait / 60) . ' мин.';
        } elseif (post_str('website', 200) !== '') {
            lab_attempt('login', strtolower($login), false);   // поле-ловушка: его заполняют только боты
            $errors[] = 'Неверный логин или пароль.';
        } else {
            $u = q('SELECT * FROM ' . T('users') . ' WHERE login = ?', [$login])->fetch();
            // если логина нет, всё равно считаем хеш — по времени ответа нельзя узнать, есть ли такой логин
            $hash = $u ? $u['pass_hash'] : lab_hash_password(lab_token());
            $ok = password_verify($pass, $hash) && $u;
            lab_attempt('login', strtolower($login), (bool)$ok);
            if (!$ok) {
                $errors[] = 'Неверный логин или пароль.';
                lab_audit(null, 'login_fail', 'логин «' . $login . '»');
            } else {
                if (password_needs_rehash($u['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
                    q('UPDATE ' . T('users') . ' SET pass_hash = ? WHERE id = ?', [lab_hash_password($pass), $u['id']]);
                }
                $mfa = (int)$u['totp_enabled'] === 1;
                lab_session_start((int)$u['id'], $mfa);
                if (!$mfa) {
                    lab_audit((int)$u['id'], 'login', 'вход');
                    lab_redirect(login_next());
                }
                lab_redirect('login.php' . (get_str('next') !== '' ? '?next=' . rawurlencode(get_str('next')) : ''));
            }
        }
    } elseif ($action === 'code' && $pending) {
        if (!lab_check_csrf($pending)) {
            $errors[] = 'Форма устарела. Обновите страницу и повторите.';
        } elseif (($wait = lab_throttled('totp', (string)$pending['user_id'])) > 0) {
            $errors[] = 'Слишком много неверных кодов. Попробуйте через ' . ceil($wait / 60) . ' мин.';
        } else {
            $u = q('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$pending['user_id']])->fetch();
            $step = $u ? lab_totp_match((string)$u['totp_secret'], post_str('code', 20), (int)$u['totp_last']) : 0;
            lab_attempt('totp', (string)$pending['user_id'], $step > 0);
            if (!$step) {
                $errors[] = 'Код не подошёл. Проверьте, что время на телефоне точное.';
            } else {
                q('UPDATE ' . T('users') . ' SET totp_last = ? WHERE id = ?', [$step, $u['id']]);
                // после второго шага — новая сессия с новым токеном
                q('DELETE FROM ' . T('sessions') . ' WHERE id = ?', [$pending['id']]);
                lab_session_start((int)$u['id'], false);
                lab_audit((int)$u['id'], 'login', 'вход с кодом');
                lab_redirect(login_next());
            }
        }
    } elseif ($action === 'cancel') {
        lab_session_end();
        lab_redirect('login.php');
    }
}

$pre = lab_pre_token();   // cookie ставим до начала вывода страницы
admin_page_start('Вход', '', null, true);
if ($pending): ?>
  <h1>Код из приложения</h1>
  <p class="lead">Откройте приложение-аутентификатор и введите шестизначный код для ByteBunch Lab.</p>
  <?php admin_errors($errors); ?>
  <form method="post" class="form" autocomplete="off">
    <?= lab_csrf_field($pending) ?>
    <input type="hidden" name="action" value="code">
    <label class="field"><span>Код</span>
      <input class="code" type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus></label>
    <button class="btn" type="submit">Войти</button>
  </form>
  <form method="post" class="after"><?= lab_csrf_field($pending) ?><input type="hidden" name="action" value="cancel"><button class="link" type="submit">Отмена</button></form>
<?php else: ?>
  <h1>Вход в панель</h1>
  <?php admin_errors($errors); ?>
  <form method="post" class="form">
    <input type="hidden" name="_csrf" value="<?= h($pre) ?>">
    <input type="hidden" name="action" value="login">
    <label class="field"><span>Логин</span>
      <input type="text" name="login" value="<?= h($login) ?>" required maxlength="64" autocomplete="username" autocapitalize="off" spellcheck="false" autofocus></label>
    <label class="field"><span>Пароль</span>
      <input type="password" name="password" required maxlength="300" autocomplete="current-password"></label>
    <label class="trap" aria-hidden="true">Сайт <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    <button class="btn" type="submit">Войти</button>
  </form>
  <p class="after"><a href="reset.php">Забыли пароль?</a></p>
<?php endif;
admin_page_end();
