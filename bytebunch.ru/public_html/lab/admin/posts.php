<?php
// Список постов.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/content.php';

$s = admin_boot();
$filter = get_str('f');
$where = in_array($filter, ['published', 'draft'], true) ? " WHERE status = '" . $filter . "'" : '';
$posts = q('SELECT id, slug, title, tag, status, featured, date_label, published_at, body, link, updated_at FROM ' . T('posts') . $where . ' ORDER BY published_at DESC, id DESC')->fetchAll();
$counts = ['' => 0, 'published' => 0, 'draft' => 0];
foreach (q('SELECT status, COUNT(*) n FROM ' . T('posts') . ' GROUP BY status')->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['n'];
    $counts[''] += (int)$r['n'];
}
$base = lab_base();

admin_page_start('Посты', 'posts', $s);
?>
<div class="head">
  <div><h1>Посты</h1><p class="sub">новости на главной и на странице «Новости»</p></div>
  <div class="acts"><a class="btn" href="post.php">Новый пост</a></div>
</div>
<?php admin_flash(); ?>
<nav class="toolbar" aria-label="Фильтр">
  <?php foreach (['' => 'Все', 'published' => 'Опубликованные', 'draft' => 'Черновики'] as $k => $label): ?>
    <a class="btn sm <?= $filter === $k ? '' : 'line' ?>" href="posts.php<?= $k ? '?f=' . $k : '' ?>"><?= $label ?> · <?= $counts[$k] ?></a>
  <?php endforeach; ?>
</nav>
<ul class="rows">
  <?php foreach ($posts as $p): [$date] = lab_post_date($p); $href = lab_post_href($p, $base); ?>
  <li>
    <div class="t">
      <a class="name" href="post.php?id=<?= (int)$p['id'] ?>"><?= h(md_title_plain($p['title'])) ?></a>
      <div class="meta">
        <span class="em"><?= h($date) ?></span>
        <?php if ($p['tag'] !== ''): ?><span><?= h($p['tag']) ?></span><?php endif; ?>
        <?= admin_badge($p['status']) ?>
        <?php if ((int)$p['featured']): ?><span class="badge star">главная новость</span><?php endif; ?>
        <span><?= trim((string)$p['body']) !== '' ? 'своя страница' : ($p['link'] !== '' ? '→ ' . h($p['link']) : 'без ссылки') ?></span>
      </div>
    </div>
    <div class="r">
      <?php if ($href): ?><a class="btn sm line" href="<?= h($href) ?>" target="_blank" rel="noopener">Открыть ↗</a><?php endif; ?>
      <a class="btn sm line" href="post.php?id=<?= (int)$p['id'] ?>">Изменить</a>
    </div>
  </li>
  <?php endforeach; ?>
  <?php if (!$posts): ?><li><span class="empty">Здесь пока пусто.</span></li><?php endif; ?>
</ul>
<?php admin_page_end();
