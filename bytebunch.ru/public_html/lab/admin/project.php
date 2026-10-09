<?php
// Управление одним HTML-проектом: ссылки, доступ, новая версия файлов, новый ключ, удаление.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/projects.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$ajax = isset($_SERVER['HTTP_X_LAB_AJAX']);
$id = (int)get_str('id');
$p = q('SELECT * FROM ' . T('projects') . ' WHERE id = ?', [$id])->fetch();
if (!$p) lab_redirect('projects.php');

$errors = [];
$form = $p;

if (lab_post_too_big()) {
    $errors[] = 'Файл больше, чем разрешает хостинг (' . lab_bytes(lab_upload_limit()) . ').';
    if ($ajax) lab_json(['error' => $errors[0]], 413);
} elseif (is_post()) {
    $action = post_str('action', 12);

    if ($action === 'settings') {
        $title = trim(post_str('title', 200));
        $slug = strtolower(trim(post_str('slug', 100)));
        $vis = in_array(post_str('visibility', 12), ['public', 'key', 'private'], true) ? post_str('visibility', 12) : 'key';
        $form = ['title' => $title, 'slug' => $slug, 'description' => trim(post_str('description', 1000)), 'visibility' => $vis,
            'sandbox' => isset($_POST['sandbox']) ? 1 : 0, 'listed' => isset($_POST['listed']) && $vis === 'public' ? 1 : 0] + $p;
        if ($title === '') $errors[] = 'Нужно название.';
        if (!lab_valid_slug($slug)) $errors[] = 'Адрес: латиница, цифры и дефисы.';
        elseif (q('SELECT id FROM ' . T('projects') . ' WHERE slug = ? AND id <> ?', [$slug, $id])->fetchColumn()) $errors[] = 'Адрес «' . $slug . '» уже занят.';
        if (!$errors) {
            q('UPDATE ' . T('projects') . ' SET title = ?, slug = ?, description = ?, visibility = ?, sandbox = ?, listed = ?, updated_at = ? WHERE id = ?',
                [$form['title'], $form['slug'], $form['description'], $form['visibility'], $form['sandbox'], $form['listed'], lab_now(), $id]);
            lab_audit($uid, 'project_save', '«' . $form['title'] . '»: доступ ' . $form['visibility'] . ($form['sandbox'] ? '' : ', без изоляции'));
            lab_redirect('project.php?id=' . $id . '&msg=saved');
        }
    }

    if ($action === 'upload') {
        try {
            $r = lab_project_store($id, $_FILES['file'] ?? []);
            q('UPDATE ' . T('projects') . ' SET entry = ?, size_bytes = ?, files_count = ?, updated_at = ? WHERE id = ?', [$r['entry'], $r['size'], $r['count'], lab_now(), $id]);
            lab_audit($uid, 'project_upload', '«' . $p['title'] . '»: новая версия, ' . $r['count'] . ' файл(ов)');
            $to = 'project.php?id=' . $id . '&msg=uploaded' . ($r['skipped'] ? '&skipped=' . count($r['skipped']) : '');
            if ($ajax) lab_json(['redirect' => $to]);
            lab_redirect($to);
        } catch (RuntimeException $e) {
            if ($ajax) lab_json(['error' => $e->getMessage()], 400);
            $errors[] = $e->getMessage();
        }
    }

    if ($action === 'rekey') {
        q('UPDATE ' . T('projects') . ' SET access_key = ?, updated_at = ? WHERE id = ?', [lab_key(), lab_now(), $id]);
        lab_audit($uid, 'project_rekey', '«' . $p['title'] . '»: новый ключ');
        lab_redirect('project.php?id=' . $id . '&msg=key');
    }

    if ($action === 'delete') {
        q('DELETE FROM ' . T('projects') . ' WHERE id = ?', [$id]);
        lab_rmtree(lab_project_dir($id));
        lab_audit($uid, 'project_delete', '«' . $p['title'] . '» (' . $p['slug'] . ')');
        lab_redirect('projects.php?msg=deleted');
    }
}

$files = lab_project_files($id, 200);
$public = lab_abs_url(lab_project_path($p));
$keyed = lab_abs_url(lab_project_path($p, 'key'));
$temp = lab_url(lab_project_path($p, 'temp'));
$skipped = (int)get_str('skipped');

admin_page_start($p['title'], 'projects', $s);
?>
<div class="head">
  <div><p class="crumb"><a href="projects.php">Проекты</a> / p/<?= h($p['slug']) ?>/</p><h1><?= h($p['title']) ?></h1>
    <p class="sub"><?= (int)$p['files_count'] ?> файл(ов) · <?= lab_bytes((int)$p['size_bytes']) ?> · открыт <?= (int)$p['views'] ?> раз · обновлён <?= h(lab_local($p['updated_at'])) ?></p></div>
  <div class="acts"><a class="btn" href="<?= h($temp) ?>" target="_blank" rel="noopener">Открыть ↗</a></div>
</div>
<?php admin_flash(); admin_errors($errors); ?>
<?php if ($skipped): ?><div class="notice warn"><p>Из архива пропущено файлов: <?= $skipped ?> — скрытые, исполняемые (php и т. п.) или неизвестных типов.</p></div><?php endif; ?>

<section class="card">
  <h2 class="sec">Ссылка</h2>
  <?php if ($p['visibility'] === 'public'): ?>
    <p class="hint">Проект открыт всем по этой ссылке.</p>
    <div class="copy"><input type="text" id="u-public" value="<?= h($public) ?>" readonly data-select-all><button class="btn sm line" type="button" data-copy="#u-public">Копировать</button></div>
    <p class="hint">Встроить в пост: <code>{{project:<?= h($p['slug']) ?>}}</code></p>
  <?php elseif ($p['visibility'] === 'key'): ?>
    <p class="hint">Секретная ссылка: кому отправите — тот и откроет. Без ключа адрес отвечает «страницы нет».</p>
    <div class="copy"><input type="text" id="u-key" value="<?= h($keyed) ?>" readonly data-select-all><button class="btn sm line" type="button" data-copy="#u-key">Копировать</button></div>
    <form method="post" class="actions" data-confirm="Сделать новый ключ? Старая ссылка сразу перестанет работать у всех, кому вы её отправляли.">
      <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="rekey"><button class="btn sm line" type="submit">Новый ключ</button>
      <span class="hint">если ссылка попала не к тем людям</span>
    </form>
  <?php else: ?>
    <p class="hint">Проект виден только вам. Кнопка «Открыть» даёт временную ссылку на 12 часов.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2 class="sec">Настройки</h2>
  <form method="post" class="form">
    <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="settings">
    <div class="grid2">
      <label class="field"><span>Название</span><input type="text" name="title" value="<?= h($form['title']) ?>" required maxlength="200"></label>
      <label class="field"><span>Адрес <small>при смене старые ссылки перестанут работать</small></span><input type="text" name="slug" value="<?= h($form['slug']) ?>" required maxlength="80" pattern="[a-z0-9\-]+" autocapitalize="off" spellcheck="false"></label>
    </div>
    <label class="field"><span>Описание</span><input type="text" name="description" value="<?= h($form['description']) ?>" maxlength="1000"></label>
    <?php include __DIR__ . '/../../../private/lab/app/project-access.php'; ?>
    <div class="actions"><button class="btn" type="submit">Сохранить</button></div>
  </form>
</section>

<section class="card">
  <h2 class="sec">Новая версия</h2>
  <form method="post" enctype="multipart/form-data" class="form" data-upload action="project.php?id=<?= (int)$id ?>">
    <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="upload">
    <label class="field"><span>Файл <small>.html или .zip — заменит все файлы проекта, ссылки останутся прежними. До <?= lab_bytes(lab_upload_limit()) ?>.</small></span>
      <input type="file" name="file" accept=".html,.htm,.zip,text/html,application/zip" required></label>
    <progress data-progress max="1" value="0" hidden></progress>
    <div class="notice err" data-upload-error hidden></div>
    <div class="actions"><button class="btn line" type="submit">Загрузить новую версию</button></div>
  </form>
</section>

<section class="card">
  <h2 class="sec">Файлы</h2>
  <div class="scroll-x"><table class="t">
    <tr><th>Путь</th><th class="n">Размер</th></tr>
    <?php foreach ($files as $f): ?>
      <tr><td class="mono"><?= h($f['path']) ?><?= $f['path'] === $p['entry'] ? ' <span class="badge pub">главная</span>' : '' ?></td><td class="n"><?= lab_bytes((int)$f['size']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$files): ?><tr><td colspan="2" class="hint">Файлов нет — загрузите новую версию.</td></tr><?php endif; ?>
  </table></div>
  <?php if ((int)$p['files_count'] > count($files)): ?><p class="hint">Показаны первые <?= count($files) ?> из <?= (int)$p['files_count'] ?>.</p><?php endif; ?>
</section>

<form method="post" class="actions" data-confirm="Удалить проект «<?= h($p['title']) ?>» вместе со всеми файлами? Это нельзя отменить.">
  <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="delete">
  <button class="btn danger sm" type="submit">Удалить проект</button>
</form>
<?php admin_page_end();
