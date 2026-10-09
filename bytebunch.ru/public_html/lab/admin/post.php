<?php
// Редактор поста.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/content.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$base = lab_base();
$id = (int)get_str('id');
$post = $id ? q('SELECT * FROM ' . T('posts') . ' WHERE id = ?', [$id])->fetch() : null;
if ($id && !$post) lab_redirect('posts.php');

$blank = ['id' => 0, 'slug' => '', 'title' => '', 'tag' => 'Релиз', 'summary' => '', 'body' => '', 'link' => '', 'cover' => '', 'cover_caption' => '',
    'status' => 'draft', 'featured' => 0, 'date_label' => '', 'published_at' => date('Y-m-d')];
$p = $post ?: $blank;
$errors = [];

if (is_post()) {
    $action = post_str('action', 10);

    if ($action === 'delete' && $post) {
        q('DELETE FROM ' . T('posts') . ' WHERE id = ?', [$post['id']]);
        lab_audit($uid, 'post_delete', '«' . md_title_plain($post['title']) . '» (' . $post['slug'] . ')');
        lab_redirect('posts.php?msg=deleted');
    }

    if ($action === 'save') {
        $p = [
            'id' => $post ? (int)$post['id'] : 0,
            'title' => trim(post_str('title', 200)),
            'slug' => strtolower(trim(post_str('slug', 100))),
            'tag' => trim(post_str('tag', 40)),
            'summary' => trim(post_str('summary', 1000)),
            'body' => rtrim(post_str('body', 400000)),
            'link' => trim(post_str('link', 500)),
            'cover' => trim(post_str('cover', 200)),
            'cover_caption' => trim(post_str('cover_caption', 300)),
            'status' => post_str('status', 12) === 'published' ? 'published' : 'draft',
            'featured' => isset($_POST['featured']) ? 1 : 0,
            'date_label' => trim(post_str('date_label', 40)),
            'published_at' => post_str('published_at', 10),
        ];
        if ($p['title'] === '') $errors[] = 'Нужен заголовок.';
        if ($p['slug'] === '') $p['slug'] = lab_slugify(md_title_plain($p['title']));
        if (!lab_valid_slug($p['slug'])) $errors[] = 'Адрес: латиница, цифры и дефисы, например «aria-0-7».';
        $dup = q('SELECT id FROM ' . T('posts') . ' WHERE slug = ? AND id <> ?', [$p['slug'], $p['id']])->fetchColumn();
        if ($dup) $errors[] = 'Пост с адресом «' . $p['slug'] . '» уже есть — придумайте другой.';
        $d = DateTime::createFromFormat('!Y-m-d', $p['published_at']);
        if (!$d || $d->format('Y-m-d') !== $p['published_at']) $errors[] = 'Дата в формате ГГГГ-ММ-ДД.';
        if ($p['link'] !== '' && md_url($p['link']) === null) $errors[] = 'Ссылка должна начинаться с https:// или быть адресом на сайте, например «aria/#new».';
        if ($p['cover'] !== '' && $p['cover'] !== 'aria-dial' && md_url($p['cover'], true) === null) $errors[] = 'Обложка: загрузите картинку или укажите адрес https://…';

        if (!$errors) {
            $now = lab_now();
            if ($p['featured']) q('UPDATE ' . T('posts') . ' SET featured = 0 WHERE id <> ?', [$p['id']]);
            $fields = [$p['slug'], $p['title'], $p['tag'], $p['summary'], $p['body'], $p['link'], $p['cover'], $p['cover_caption'],
                $p['status'], $p['featured'], $p['date_label'], $p['published_at']];
            if ($p['id']) {
                q('UPDATE ' . T('posts') . ' SET slug = ?, title = ?, tag = ?, summary = ?, body = ?, link = ?, cover = ?, cover_caption = ?, status = ?, featured = ?, date_label = ?, published_at = ?, updated_at = ? WHERE id = ?',
                    array_merge($fields, [$now, $p['id']]));
                lab_audit($uid, 'post_save', '«' . md_title_plain($p['title']) . '» — ' . ($p['status'] === 'published' ? 'опубликован' : 'черновик'));
                lab_redirect('post.php?id=' . $p['id'] . '&msg=saved');
            }
            q('INSERT INTO ' . T('posts') . ' (slug, title, tag, summary, body, link, cover, cover_caption, status, featured, date_label, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($fields, [$now, $now]));
            $newId = (int)lab_db()->lastInsertId();
            lab_audit($uid, 'post_create', '«' . md_title_plain($p['title']) . '»');
            lab_redirect('post.php?id=' . $newId . '&msg=created');
        }
    }
}

$view = $p['id'] ? lab_post_href($p, $base) : null;
$coverSrc = lab_cover_src($p['cover'], $base);
admin_page_start($p['id'] ? 'Пост' : 'Новый пост', 'posts', $s);
?>
<div class="head">
  <div><p class="crumb"><a href="posts.php">Посты</a> / <?= $p['id'] ? h(md_title_plain($p['title'])) : 'новый' ?></p>
    <h1><?= $p['id'] ? 'Редактирование' : 'Новый пост' ?></h1></div>
  <?php if ($view): ?><div class="acts"><a class="btn line" href="<?= h($view) ?>" target="_blank" rel="noopener">Посмотреть на сайте ↗</a></div><?php endif; ?>
</div>
<?php admin_flash(); admin_errors($errors); ?>

<form method="post" class="editor" id="post-form" data-dirty-guard>
  <?= lab_csrf_field($s) ?>
  <input type="hidden" name="action" value="save">
  <div class="form">
    <label class="field"><span>Заголовок <small>*звёздочками* можно выделить слово цветом, как «Aria» в «Aria 0.6.2»</small></span>
      <input type="text" name="title" value="<?= h($p['title']) ?>" required maxlength="200"></label>
    <label class="field"><span>Короткое описание <small>видно в списке новостей и под заголовком</small></span>
      <textarea name="summary" rows="3" maxlength="1000"><?= h($p['summary']) ?></textarea></label>
    <div class="field">
      <span>Текст поста <small>если заполнен, у поста будет своя страница news/<?= h($p['slug'] ?: 'адрес') ?>; если пуст — пост ведёт по ссылке справа</small></span>
      <div class="toolbar" data-md-toolbar="body">
        <button type="button" data-md="h">Заголовок</button>
        <button type="button" data-md="b"><b>Ж</b></button>
        <button type="button" data-md="i"><i>К</i></button>
        <button type="button" data-md="link">Ссылка</button>
        <button type="button" data-md="ul">• Список</button>
        <button type="button" data-md="quote">Цитата</button>
        <button type="button" data-md="code">Код</button>
        <button type="button" data-md="img">Картинка…</button>
        <button type="button" data-md="project">Проект</button>
      </div>
      <textarea class="body" name="body" id="body" spellcheck="true"><?= h($p['body']) ?></textarea>
      <input type="file" id="body-image" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <p class="mdhelp"><b>## Подзаголовок</b> · <b>**жирный**</b> · <b>*курсив*</b> · <b>[текст](https://…)</b> · <b>- пункт</b> · <b>&gt; цитата</b> · <b>![подпись](картинка)</b> · <b>{{project:адрес}}</b> — встроить открытый HTML-проект</p>
    </div>
  </div>

  <aside>
    <fieldset>
      <legend>Публикация</legend>
      <div class="radios">
        <label class="check"><input type="radio" name="status" value="draft"<?= admin_checked($p['status'] !== 'published') ?>><span><b>Черновик</b><small>видите только вы</small></span></label>
        <label class="check"><input type="radio" name="status" value="published"<?= admin_checked($p['status'] === 'published') ?>><span><b>Опубликован</b><small>виден всем</small></span></label>
      </div>
      <label class="check"><input type="checkbox" name="featured" value="1"<?= admin_checked((bool)$p['featured']) ?>><span><b>Главная новость</b><small>крупно в начале раздела «Новости» и в строке на первом экране</small></span></label>
      <div class="actions">
        <button class="btn" type="submit">Сохранить</button>
      </div>
    </fieldset>

    <fieldset>
      <legend>Дата и метка</legend>
      <label class="field"><span>Дата <small>по ней сортируются новости</small></span><input type="date" name="published_at" value="<?= h($p['published_at']) ?>" required></label>
      <label class="field"><span>Вместо даты показать <small>например «скоро» или «10.2026»; пусто — показывать дату</small></span><input type="text" name="date_label" value="<?= h($p['date_label']) ?>" maxlength="40"></label>
      <label class="field"><span>Тип</span><input type="text" name="tag" value="<?= h($p['tag']) ?>" list="tags" maxlength="40"></label>
      <datalist id="tags"><?php foreach (LAB_TAGS as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist>
    </fieldset>

    <fieldset>
      <legend>Адрес и ссылка</legend>
      <label class="field"><span>Адрес поста <small>пусто — сделаем из заголовка</small></span><input type="text" name="slug" value="<?= h($p['slug']) ?>" maxlength="80" pattern="[a-z0-9\-]*" autocapitalize="off" spellcheck="false"></label>
      <label class="field"><span>Ссылка, если текста нет <small>например «aria/#new» или https://t.me/ByteBunch/…</small></span><input type="text" name="link" value="<?= h($p['link']) ?>" maxlength="500" autocapitalize="off" spellcheck="false"></label>
    </fieldset>

    <fieldset>
      <legend>Обложка</legend>
      <div class="cover-prev" id="cover-prev"<?= $coverSrc ? '' : ' hidden' ?>><img src="<?= h((string)$coverSrc) ?>" alt=""></div>
      <?php if ($p['cover'] === 'aria-dial'): ?><p class="hint">Сейчас: анимированная шкала Aria против GuitarTuna.</p><?php endif; ?>
      <input type="hidden" name="cover" id="cover" value="<?= h($p['cover']) ?>">
      <input type="file" id="cover-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      <div class="actions">
        <button class="btn sm line" type="button" data-pick="cover-file">Загрузить</button>
        <button class="btn sm line" type="button" data-cover-clear>Убрать</button>
      </div>
      <label class="field"><span>Подпись</span><input type="text" name="cover_caption" value="<?= h($p['cover_caption']) ?>" maxlength="300"></label>
    </fieldset>
  </aside>
</form>

<?php if ($p['id']): ?>
<form method="post" class="actions" data-confirm="Удалить пост «<?= h(md_title_plain($p['title'])) ?>»? Это нельзя отменить.">
  <?= lab_csrf_field($s) ?><input type="hidden" name="action" value="delete">
  <button class="btn danger sm" type="submit">Удалить пост</button>
</form>
<?php endif; ?>
<?php admin_page_end();
