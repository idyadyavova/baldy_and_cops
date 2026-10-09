<?php
// Безопасность: пароль, вход по коду из приложения, сессии, API-ключи и журнал действий.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$user = q('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$uid])->fetch();
$errors = [];
$newToken = null;   // API-ключ показываем один раз — сразу после создания

if (is_post()) {
    $action = post_str('action', 20);

    if ($action === 'password') {
        $cur = post_str('current', 300);
        $new = post_str('password', 300);
        if (($wait = lab_throttled('login', strtolower($user['login']))) > 0) {
            $errors[] = 'Слишком много неверных попыток. Подождите ' . ceil($wait / 60) . ' мин.';
        } elseif (!password_verify($cur, $user['pass_hash'])) {
            lab_attempt('login', strtolower($user['login']), false);
            $errors[] = 'Текущий пароль неверный.';
        } elseif ($p = lab_password_problem($new, $user['login'])) {
            $errors[] = $p;
        } elseif ($new !== post_str('password2', 300)) {
            $errors[] = 'Новые пароли не совпадают.';
        } else {
            q('UPDATE ' . T('users') . ' SET pass_hash = ?, pass_changed_at = ? WHERE id = ?', [lab_hash_password($new), lab_now(), $uid]);
            q('DELETE FROM ' . T('sessions') . ' WHERE user_id = ? AND id <> ?', [$uid, $s['id']]);
            lab_audit($uid, 'password', 'пароль изменён, другие сессии завершены');
            lab_redirect('security.php?msg=pass');
        }
    }

    if ($action === '2fa_begin' && !(int)$user['totp_enabled']) {
        q('UPDATE ' . T('users') . ' SET totp_secret = ? WHERE id = ?', [lab_base32_encode(random_bytes(20)), $uid]);
        lab_redirect('security.php?setup=2fa#twofa');
    }

    if ($action === '2fa_confirm' && !(int)$user['totp_enabled'] && $user['totp_secret']) {
        $step = lab_totp_match($user['totp_secret'], post_str('code', 20));
        if (!$step) {
            $errors[] = 'Код не подошёл. Проверьте, что время на телефоне выставлено автоматически, и введите свежий код.';
            $_GET['setup'] = '2fa';
        } else {
            q('UPDATE ' . T('users') . ' SET totp_enabled = 1, totp_last = ? WHERE id = ?', [$step, $uid]);
            q('DELETE FROM ' . T('sessions') . ' WHERE user_id = ? AND id <> ?', [$uid, $s['id']]);
            lab_audit($uid, '2fa_on', 'вход по коду включён');
            lab_redirect('security.php?msg=2fa_on');
        }
    }

    if ($action === '2fa_off' && (int)$user['totp_enabled']) {
        if (!password_verify(post_str('current', 300), $user['pass_hash'])) {
            $errors[] = 'Пароль неверный.';
        } elseif (!lab_totp_match($user['totp_secret'], post_str('code', 20), (int)$user['totp_last'])) {
            $errors[] = 'Код не подошёл.';
        } else {
            q('UPDATE ' . T('users') . ' SET totp_enabled = 0, totp_secret = NULL WHERE id = ?', [$uid]);
            lab_audit($uid, '2fa_off', 'вход по коду выключен');
            lab_redirect('security.php?msg=2fa_off');
        }
    }

    if ($action === 'end') {
        q('DELETE FROM ' . T('sessions') . ' WHERE id = ? AND user_id = ? AND id <> ?', [post_str('sid', 64), $uid, $s['id']]);
        lab_audit($uid, 'session_end', 'завершена одна сессия');
        lab_redirect('security.php?msg=ended#sessions');
    }
    if ($action === 'end_others') {
        q('DELETE FROM ' . T('sessions') . ' WHERE user_id = ? AND id <> ?', [$uid, $s['id']]);
        lab_audit($uid, 'session_end', 'завершены все сессии, кроме текущей');
        lab_redirect('security.php?msg=ended#sessions');
    }

    if ($action === 'key_create') {
        $name = trim(post_str('name', 100));
        $scopes = array_values(array_intersect(['posts', 'projects'], (array)($_POST['scopes'] ?? [])));
        if ($name === '') $errors[] = 'Назовите ключ — например, «ноутбук» или «скрипт сборки».';
        if (!$scopes) $errors[] = 'Отметьте, что можно делать этим ключом.';
        if (!$errors) {
            $prefix = strtolower(lab_key(8));
            $newToken = 'bbk_' . $prefix . '_' . lab_key(40);
            q('INSERT INTO ' . T('api_keys') . ' (user_id, name, prefix, key_hash, scopes, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$uid, $name, $prefix, hash('sha256', $newToken), implode(',', $scopes), lab_now()]);
            lab_audit($uid, 'key_create', '«' . $name . '» (' . implode(', ', $scopes) . ')');
        }
    }
    if ($action === 'key_revoke') {
        q('UPDATE ' . T('api_keys') . ' SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [lab_now(), (int)post_str('id', 12), $uid]);
        lab_audit($uid, 'key_revoke', 'ключ #' . (int)post_str('id', 12));
        lab_redirect('security.php?msg=revoked#keys');
    }
}

$user = q('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$uid])->fetch();
$sessions = q('SELECT * FROM ' . T('sessions') . ' WHERE user_id = ? AND mfa_pending = 0 ORDER BY last_seen DESC', [$uid])->fetchAll();
$keys = q('SELECT * FROM ' . T('api_keys') . ' WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, created_at DESC', [$uid])->fetchAll();
$log = q('SELECT * FROM ' . T('audit') . ' ORDER BY id DESC LIMIT 60')->fetchAll();
$setup2fa = get_str('setup') === '2fa' && !(int)$user['totp_enabled'] && $user['totp_secret'];

/** Коротко о браузере для списка сессий. */
function ua_short(string $ua): string
{
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iOS' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : ''))));
    $br = preg_match('/YaBrowser/i', $ua) ? 'Яндекс Браузер' : (preg_match('/Edg\//i', $ua) ? 'Edge' : (preg_match('/OPR\//i', $ua) ? 'Opera' : (preg_match('/Firefox/i', $ua) ? 'Firefox' : (preg_match('/Chrome/i', $ua) ? 'Chrome' : (preg_match('/Safari/i', $ua) ? 'Safari' : (preg_match('/curl/i', $ua) ? 'curl' : 'браузер'))))));
    return trim($br . ($os ? ' · ' . $os : ''));
}

admin_page_start('Безопасность', 'security', $s);
?>
<div class="head"><div><h1>Безопасность</h1><p class="sub">вход «<?= h($user['login']) ?>» · пароль менялся <?= h(lab_local($user['pass_changed_at'], 'd.m.Y')) ?></p></div></div>
<?php admin_flash(); admin_errors($errors); ?>

<?php if ($newToken): ?>
<section class="card" id="keys">
  <h2 class="sec">Новый API-ключ</h2>
  <p class="hint">Скопируйте ключ сейчас — больше он показан не будет. В базе хранится только его отпечаток.</p>
  <div class="token-once" id="new-token"><?= h($newToken) ?></div>
  <div class="actions"><button class="btn sm" type="button" data-copy="#new-token">Копировать</button></div>
</section>
<?php endif; ?>

<section class="card" id="twofa">
  <h2 class="sec">Вход по коду из приложения <?= (int)$user['totp_enabled'] ? '<span class="badge pub">включён</span>' : '<span class="badge off">выключен</span>' ?></h2>
  <?php if ((int)$user['totp_enabled']): ?>
    <p class="hint">После пароля панель спрашивает шестизначный код из приложения-аутентификатора. Даже если пароль украдут, без телефона войти не получится.</p>
    <details>
      <summary class="hint">Выключить</summary>
      <form method="post" class="form" autocomplete="off">
        <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="2fa_off">
        <div class="grid2">
          <label class="field"><span>Пароль</span><input type="password" name="current" required autocomplete="current-password"></label>
          <label class="field"><span>Код из приложения</span><input type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code"></label>
        </div>
        <div class="actions"><button class="btn danger sm" type="submit">Выключить вход по коду</button></div>
      </form>
    </details>
  <?php elseif ($setup2fa): $uri = lab_totp_uri($user['totp_secret'], $user['login']); ?>
    <ol class="steps">
      <li>Поставьте приложение-аутентификатор: Google Authenticator, Яндекс Ключ, Aegis, 2FAS или любое другое с кодами TOTP.</li>
      <li>Отсканируйте QR-код. Если сканировать нечем — выберите в приложении «Ввести ключ вручную» и перепишите ключ под QR-кодом.</li>
      <li>Введите код, который покажет приложение.</li>
    </ol>
    <div class="qr" data-qr="<?= h($uri) ?>" role="img" aria-label="QR-код для приложения-аутентификатора"></div>
    <p class="secret"><?= h(trim(chunk_split($user['totp_secret'], 4, ' '))) ?></p>
    <p class="hint"><a href="<?= h($uri) ?>">Открыть в приложении на этом телефоне</a></p>
    <form method="post" class="form" autocomplete="off">
      <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="2fa_confirm">
      <label class="field"><span>Код из приложения</span><input class="code" type="text" name="code" inputmode="numeric" maxlength="7" required autocomplete="one-time-code" autofocus></label>
      <div class="actions"><button class="btn" type="submit">Включить</button></div>
    </form>
    <p class="hint">Если потеряете телефон — сбросьте вход через «Забыли пароль?» на странице входа: понадобится пароль базы данных из config.php.</p>
  <?php else: ?>
    <p class="hint">Рекомендуем: после пароля панель будет спрашивать шестизначный код из приложения на телефоне. Украденного пароля станет мало, чтобы войти.</p>
    <form method="post" class="actions"><?= lab_csrf_field($s) ?><input type="hidden" name="action" value="2fa_begin"><button class="btn" type="submit">Включить вход по коду</button></form>
  <?php endif; ?>
</section>

<section class="card">
  <h2 class="sec">Пароль</h2>
  <form method="post" class="form">
    <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="password">
    <input type="text" name="username" value="<?= h($user['login']) ?>" autocomplete="username" hidden>
    <label class="field"><span>Текущий пароль</span><input type="password" name="current" required autocomplete="current-password"></label>
    <div class="grid2">
      <label class="field"><span>Новый пароль <small>не короче 12 символов</small></span><input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
      <label class="field"><span>Ещё раз</span><input type="password" name="password2" required minlength="12" autocomplete="new-password"></label>
    </div>
    <div class="actions"><button class="btn" type="submit">Сменить пароль</button><span class="hint">остальные устройства выйдут из панели</span></div>
  </form>
</section>

<section class="card" id="sessions">
  <h2 class="sec">Где выполнен вход</h2>
  <ul class="rows">
    <?php foreach ($sessions as $x): ?>
    <li>
      <div class="t"><b><?= h(ua_short($x['ua'])) ?></b><?= $x['id'] === $s['id'] ? ' <span class="badge pub">это устройство</span>' : '' ?>
        <div class="meta"><span>IP <?= h($x['ip']) ?></span><span>вход <?= h(lab_local($x['created_at'])) ?></span><span>активность <?= h(lab_local($x['last_seen'])) ?></span></div></div>
      <div class="r"><?php if ($x['id'] !== $s['id']): ?><form method="post"><?= lab_csrf_field($s) ?><input type="hidden" name="action" value="end"><input type="hidden" name="sid" value="<?= h($x['id']) ?>"><button class="btn sm line" type="submit">Завершить</button></form><?php endif; ?></div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if (count($sessions) > 1): ?>
  <form method="post" class="actions"><?= lab_csrf_field($s) ?><input type="hidden" name="action" value="end_others"><button class="btn sm line" type="submit">Завершить все, кроме этой</button></form>
  <?php endif; ?>
  <p class="hint">Сессия заканчивается через сутки без действий и в любом случае через 14 дней.</p>
</section>

<section class="card" id="keys">
  <h2 class="sec">API-ключи</h2>
  <p class="hint">Для скриптов: загрузить проект или опубликовать пост без входа в панель. Ключ передаётся в заголовке
    <code>Authorization: Bearer …</code> на адрес <code><?= h(lab_abs_url('api/')) ?></code>. Примеры — в README.</p>
  <ul class="rows">
    <?php foreach ($keys as $k): ?>
    <li>
      <div class="t"><b><?= h($k['name']) ?></b> <?= $k['revoked_at'] ? '<span class="badge off">отозван</span>' : '' ?>
        <div class="meta"><span class="em">bbk_<?= h($k['prefix']) ?>_…</span><span><?= h(str_replace(['posts', 'projects', ','], ['посты', 'проекты', ', '], $k['scopes'])) ?></span>
          <span>создан <?= h(lab_local($k['created_at'], 'd.m.Y')) ?></span><span>использован <?= h(lab_local($k['last_used_at'])) ?></span></div></div>
      <div class="r"><?php if (!$k['revoked_at']): ?><form method="post" data-confirm="Отозвать ключ «<?= h($k['name']) ?>»? Скрипты с ним перестанут работать."><?= lab_csrf_field($s) ?><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn sm danger" type="submit">Отозвать</button></form><?php endif; ?></div>
    </li>
    <?php endforeach; ?>
    <?php if (!$keys): ?><li><span class="empty">Ключей нет.</span></li><?php endif; ?>
  </ul>
  <form method="post" class="form">
    <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="key_create">
    <label class="field"><span>Название ключа</span><input type="text" name="name" maxlength="100" placeholder="например, «скрипт сборки Aria»"></label>
    <div class="actions">
      <label class="check"><input type="checkbox" name="scopes[]" value="projects" checked><span><b>Проекты</b><small>загружать и обновлять HTML-проекты</small></span></label>
      <label class="check"><input type="checkbox" name="scopes[]" value="posts"><span><b>Посты</b><small>создавать и менять посты</small></span></label>
    </div>
    <div class="actions"><button class="btn line" type="submit">Создать ключ</button></div>
  </form>
</section>

<section class="card">
  <h2 class="sec">Журнал</h2>
  <div class="scroll-x"><table class="t">
    <tr><th>Когда</th><th>Действие</th><th>IP</th></tr>
    <?php foreach ($log as $e): ?><tr><td class="mono"><?= h(lab_local($e['created_at'])) ?></td><td><?= h($e['details'] !== '' ? $e['details'] : $e['action']) ?></td><td class="mono"><?= h($e['ip']) ?></td></tr><?php endforeach; ?>
  </table></div>
</section>
<?php admin_page_end($setup2fa ? ['assets/qrcode.js'] : []);
