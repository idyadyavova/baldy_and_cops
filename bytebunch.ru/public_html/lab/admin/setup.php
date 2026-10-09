<?php
// Первая установка панели: таблицы в базе и вход администратора.
// Доказательство, что устанавливает владелец сайта, — пароль базы из private/lab/config.php:
// его знает только тот, у кого есть доступ к файлам хостинга.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

admin_boot(false);
$error = null;
$state = lab_install_state($error);
if ($state === 'ready') lab_redirect('index.php');

$errors = [];
$login = '';
if ($state === 'empty' && is_post()) {
    $login = trim(post_str('login', 64));
    $pass = post_str('password', 300);
    $pass2 = post_str('password2', 300);
    $dbpass = post_str('dbpass', 300);
    if (!lab_check_pre_token()) {
        $errors[] = 'Форма устарела. Обновите страницу и повторите.';
    } else {
        // пароль базы проверяем с ограничением попыток, когда таблица попыток уже есть
        try {
            lab_db()->exec(lab_schema_sql()[3]);   // таблица attempts
            $wait = lab_throttled('setup');
        } catch (Throwable $e) {
            $wait = 0;
        }
        if ($wait > 0) {
            $errors[] = 'Слишком много неверных попыток. Подождите ' . ceil($wait / 60) . ' мин.';
        } elseif ((string)lab_config()['db']['pass'] === '') {
            $errors[] = 'У базы нет пароля. Задайте пароль пользователю базы в панели хостинга и впишите его в config.php.';
        } elseif (!hash_equals((string)lab_config()['db']['pass'], $dbpass)) {
            lab_attempt('setup', '', false);
            $errors[] = 'Пароль базы не совпадает с тем, что в config.php.';
        } else {
            if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $login)) $errors[] = 'Логин: от 3 символов, латиница, цифры, точка, дефис или подчёркивание.';
            if ($p = lab_password_problem($pass, $login)) $errors[] = $p;
            if ($pass !== $pass2) $errors[] = 'Пароли не совпадают.';
            if (!$errors) {
                $uid = lab_install($login, $pass);
                lab_attempt('setup', '', true);
                lab_session_start($uid, false);
                lab_redirect('index.php?msg=created');
            }
        }
    }
}

$pre = lab_pre_token();   // cookie ставим до начала вывода страницы
admin_page_start('Установка', '', null, true);
?>
<h1>Установка панели</h1>
<?php if ($state === 'noconfig'): ?>
  <p class="lead">Панель ещё не знает, к какой базе подключаться.</p>
  <ol class="steps">
    <li>В панели Спринтхоста откройте «Базы данных» → «Добавить базу данных». Запишите имя базы, пользователя и пароль.</li>
    <li>В папке <code>private/lab/</code> сделайте копию <code>config.sample.php</code> с именем <code>config.php</code> и впишите туда эти данные.</li>
    <li>Обновите эту страницу.</li>
  </ol>
<?php elseif ($state === 'nodb'): ?>
  <p class="lead">Не получается подключиться к базе. Проверьте имя базы, пользователя и пароль в <code>private/lab/config.php</code>.</p>
  <div class="notice err"><p><?= h(preg_replace('/\(using password: \w+\)/', '', (string)$error)) ?></p></div>
<?php else: ?>
  <p class="lead">База подключена. Осталось создать вход в панель.</p>
  <?php admin_errors($errors); ?>
  <form method="post" class="form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h($pre) ?>">
    <label class="field"><span>Пароль базы данных <small>тот же, что в config.php — так панель понимает, что её ставит владелец</small></span>
      <input type="password" name="dbpass" required autocomplete="off"></label>
    <label class="field"><span>Логин для панели</span>
      <input type="text" name="login" value="<?= h($login) ?>" required minlength="3" maxlength="64" pattern="[A-Za-z0-9._\-]{3,64}" autocomplete="username" autocapitalize="off" spellcheck="false"></label>
    <label class="field"><span>Пароль <small>не короче 12 символов; лучше несколько случайных слов</small></span>
      <input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
    <label class="field"><span>Пароль ещё раз</span>
      <input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
    <button class="btn" type="submit">Установить</button>
  </form>
  <p class="hint">Панель создаст таблицы с приставкой <code><?= h(lab_config()['db']['prefix']) ?></code> и перенесёт в базу новости, которые сейчас на главной.</p>
<?php endif; ?>
<?php admin_page_end();
