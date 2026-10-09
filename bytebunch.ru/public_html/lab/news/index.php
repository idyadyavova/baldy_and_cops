<?php
// Новости: список всех постов (news/) и страница поста (news/адрес-поста).
// Черновик видно только после входа в панель — с пометкой «черновик».
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/auth.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
$base = lab_base();

// адрес поста: из пути (news/aria-0-6-2) или из ?p= — если на хостинге не работает .htaccess
$slug = get_str('p');
if ($slug === '') {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $prefix = $base . 'news/';
    if (strpos($path, $prefix) === 0) $slug = trim(substr($path, strlen($prefix)), '/');
    if ($slug === 'index.php') $slug = '';
}

$db = lab_db_or_null();
if (!$db) {
    // панель ещё не установлена — новости пока живут на главной
    lab_redirect($base . '#news', 302);
}

function news_404(string $base): void
{
    http_response_code(404);
    echo lab_site_head($base, 'Нет такой новости — ByteBunch • Lab', 'Новости ByteBunch • Lab');
    echo lab_site_nav($base, 'news');
    echo '<main class="article-wrap"><div class="wrap narrow"><p class="crumb"><a href="' . h($base) . 'news/">Новости</a></p><h1 class="a-title">Такой новости нет</h1>'
        . '<p class="a-lede">Возможно, её переименовали. Все новости — <a href="' . h($base) . 'news/">здесь</a>.</p></div></main>';
    echo lab_site_footer($base) . '<script src="' . h($base) . 'assets/lab.js"></script></body></html>';
    exit;
}

// ─── страница поста ──────────────────────────────────────────────────────────
if ($slug !== '') {
    if (!lab_valid_slug($slug)) news_404($base);
    $p = lab_post_by_slug($slug);
    $admin = lab_session();
    if (!$p || ($p['status'] !== 'published' && !$admin)) news_404($base);
    // у поста без текста своей страницы нет: отправляем туда, куда он ведёт
    if (trim((string)$p['body']) === '') {
        $to = lab_post_href($p, $base);
        if ($to && strpos($to, $base . 'news/') !== 0) lab_redirect($to, 302);
    }
    if ($p['status'] !== 'published') header('X-Robots-Tag: noindex');

    $plain = md_title_plain($p['title']);
    echo lab_site_head($base, $plain . ' — ByteBunch • Lab', $p['summary'] !== '' ? $p['summary'] : $plain);
    echo lab_site_nav($base, 'news');
    $cover = lab_cover_src($p['cover'], $base);
    $body = md_render((string)$p['body'], $base, function ($s) use ($base) { return lab_project_embed_html($s, $base); });
    ?>
<main class="article-wrap">
  <article class="wrap narrow">
    <?php if ($p['status'] !== 'published'): ?><p class="draft-note">Черновик — эту страницу видите только вы. <a href="<?= h($base) ?>admin/post.php?id=<?= (int)$p['id'] ?>">Редактировать</a></p><?php endif; ?>
    <p class="crumb"><a href="<?= h($base) ?>news/">Новости</a><?php if ($p['tag'] !== ''): ?> · <?= h($p['tag']) ?><?php endif; ?> · <?= lab_post_time_html($p) ?></p>
    <h1 class="a-title"><?= md_title($p['title']) ?></h1>
    <?php if ($p['summary'] !== ''): ?><p class="a-lede"><?= h($p['summary']) ?></p><?php endif; ?>
    <?php if ($cover): ?><figure class="a-cover"><img src="<?= h($cover) ?>" alt=""><?php if ($p['cover_caption'] !== ''): ?><figcaption><?= h($p['cover_caption']) ?></figcaption><?php endif; ?></figure><?php endif; ?>
    <div class="prose-md"><?= $body ?></div>
    <p class="a-back"><a class="more" href="<?= h($base) ?>news/"><span class="ar">←</span> Все новости</a></p>
  </article>
</main>
<?php
    echo lab_site_footer($base) . '<script src="' . h($base) . 'assets/lab.js"></script></body></html>';
    exit;
}

// ─── список ──────────────────────────────────────────────────────────────────
$posts = lab_posts_published(500);
echo lab_site_head($base, 'Новости — ByteBunch • Lab', 'Релизы, исследования и анонсы лаборатории ByteBunch.');
echo lab_site_nav($base, 'news');
?>
<main class="article-wrap">
  <section class="wrap">
    <div class="sec-head"><h2>Новости</h2><p class="meta">релизы, исследования, анонсы</p></div>
    <ol class="news">
      <?php foreach ($posts as $p): $href = lab_post_href($p, $base); ?>
      <li<?= $href ? '' : ' class="soon-row"' ?>><a<?= $href ? ' href="' . h($href) . '"' : '' ?>><?= lab_post_time_html($p) ?><span class="tag"><?= h($p['tag']) ?></span><span class="t"><b><?= h(md_title_plain($p['title'])) ?></b><?php if ($p['summary'] !== ''): ?><span><?= h($p['summary']) ?></span><?php endif; ?></span><span class="ar"><?= $href ? '→' : '' ?></span></a></li>
      <?php endforeach; ?>
      <?php if (!$posts): ?><li class="soon-row"><a><span class="t"><b>Новостей пока нет</b></span></a></li><?php endif; ?>
    </ol>
  </section>
</main>
<?php
echo lab_site_footer($base) . '<script src="' . h($base) . 'assets/lab.js"></script></body></html>';
