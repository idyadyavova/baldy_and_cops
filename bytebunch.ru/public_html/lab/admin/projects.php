<?php
// HTML-проекты: список и загрузка нового.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/projects.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$ajax = isset($_SERVER['HTTP_X_LAB_AJAX']);

/** Общая проверка полей проекта для создания и изменения. */
function project_fields(int $id): array
{
    $f = [
        'title' => trim(post_str('title', 200)),
        'slug' => strtolower(trim(post_str('slug', 100))),
        'description' => trim(post_str('description', 1000)),
        'visibility' => in_array(post_str('visibility', 12), ['public', 'key', 'private'], true) ? post_str('visibility', 12) : 'key',
        'sandbox' => isset($_POST['sandbox']) ? 1 : 0,
        'listed' => isset($_POST['listed']) ? 1 : 0,
    ];
    $errors = [];
    if ($f['title'] === '') $errors[] = 'Нужно название.';
    if ($f['slug'] === '') $f['slug'] = lab_slugify($f['title']);
    if (!lab_valid_slug($f['slug'])) $errors[] = 'Адрес: латиница, цифры и дефисы.';
    elseif (q('SELECT id FROM ' . T('projects') . ' WHERE slug = ? AND id <> ?', [$f['slug'], $id])->fetchColumn()) $errors[] = 'Адрес «' . $f['slug'] . '» уже занят.';
    if ($f['visibility'] !== 'public') $f['listed'] = 0;
    return [$f, $errors];
}

$errors = [];
$form = ['title' => '', 'slug' => '', 'description' => '', 'visibility' => 'key', 'sandbox' => 1, 'listed' => 0];
if (lab_post_too_big()) {
    $errors[] = 'Файл больше, чем разрешает хостинг (' . lab_bytes(lab_upload_limit()) . ').';
    if ($ajax) lab_json(['error' => $errors[0]], 413);
} elseif (is_post()) {
    [$form, $errors] = project_fields(0);
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) $errors[] = 'Выберите файл.';
    if (!$errors) {
        $now = lab_now();
        q('INSERT INTO ' . T('projects') . ' (slug, title, description, visibility, access_key, entry, sandbox, listed, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'index.html\', ?, ?, ?, ?)',
            [$form['slug'], $form['title'], $form['description'], $form['visibility'], lab_key(), $form['sandbox'], $form['listed'], $now, $now]);
        $id = (int)lab_db()->lastInsertId();
        try {
            $r = lab_project_store($id, $_FILES['file']);
            q('UPDATE ' . T('projects') . ' SET entry = ?, size_bytes = ?, files_count = ? WHERE id = ?', [$r['entry'], $r['size'], $r['count'], $id]);
            lab_audit($uid, 'project_create', '«' . $form['title'] . '» (' . $form['slug'] . '), ' . $r['count'] . ' файл(ов)');
            $to = 'project.php?id=' . $id . '&msg=uploaded' . ($r['skipped'] ? '&skipped=' . count($r['skipped']) : '');
            if ($ajax) lab_json(['redirect' => $to]);
            lab_redirect($to);
        } catch (RuntimeException $e) {
            q('DELETE FROM ' . T('projects') . ' WHERE id = ?', [$id]);
            $errors[] = $e->getMessage();
        }
    }
    if ($ajax) lab_json(['error' => implode(' ', $errors)], 400);
}

$projects = q('SELECT * FROM ' . T('projects') . ' ORDER BY updated_at DESC')->fetchAll();
$limit = lab_upload_limit();

admin_page_start('Проекты', 'projects', $s);
?>
<div class="head">
  <div><h1>HTML-проекты</h1><p class="sub">файлы лежат вне public_html и отдаются только через проверку доступа</p></div>
</div>
<?php admin_flash(); admin_errors($errors); ?>

<ul class="rows">
  <?php foreach ($projects as $p): ?>
  <li>
    <div class="t">
      <a class="name" href="project.php?id=<?= (int)$p['id'] ?>"><?= h($p['title']) ?></a>
      <div class="meta">
        <span class="em">p/<?= h($p['slug']) ?>/</span>
        <?= admin_badge($p['visibility']) ?>
        <?php if (!(int)$p['sandbox']): ?><span class="badge off">без изоляции</span><?php endif; ?>
        <?php if ((int)$p['listed']): ?><span class="badge star">на главной</span><?php endif; ?>
        <span><?= (int)$p['files_count'] ?> ф. · <?= lab_bytes((int)$p['size_bytes']) ?></span>
        <span><?= (int)$p['views'] ?> откр.</span>
      </div>
    </div>
    <div class="r"><a class="btn sm line" href="project.php?id=<?= (int)$p['id'] ?>">Управление</a></div>
  </li>
  <?php endforeach; ?>
  <?php if (!$projects): ?><li><span class="empty">Проектов пока нет — загрузите первый ниже.</span></li><?php endif; ?>
</ul>

<section class="card">
  <h2 class="sec">Загрузить проект</h2>
  <form method="post" enctype="multipart/form-data" class="form" data-upload>
    <?= lab_csrf_field($s) ?>
    <label class="field"><span>Файл <small>один .html или .zip с index.html внутри (картинки, скрипты, звуки — рядом). До <?= lab_bytes($limit) ?>.</small></span>
      <input type="file" name="file" accept=".html,.htm,.zip,text/html,application/zip" required></label>
    <div class="grid2">
      <label class="field"><span>Название</span><input type="text" name="title" value="<?= h($form['title']) ?>" required maxlength="200" data-slug-source></label>
      <label class="field"><span>Адрес <small>lab.bytebunch.ru/p/<b>адрес</b>/</small></span><input type="text" name="slug" value="<?= h($form['slug']) ?>" maxlength="80" pattern="[a-z0-9\-]*" autocapitalize="off" spellcheck="false" data-slug-target></label>
    </div>
    <label class="field"><span>Описание <small>для списка экспериментов, необязательно</small></span><input type="text" name="description" value="<?= h($form['description']) ?>" maxlength="1000"></label>
    <?php include __DIR__ . '/../../../private/lab/app/project-access.php'; ?>
    <progress data-progress max="1" value="0" hidden></progress>
    <div class="notice err" data-upload-error hidden></div>
    <div class="actions"><button class="btn" type="submit">Загрузить</button></div>
  </form>
</section>
<?php admin_page_end();
