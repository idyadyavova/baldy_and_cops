<?php
// Обзор: что опубликовано, что в черновиках, проекты, Sionika и состояние защиты.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/sionika.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$user = q('SELECT * FROM ' . T('users') . ' WHERE id = ?', [$uid])->fetch();
$posts = q('SELECT status, COUNT(*) n FROM ' . T('posts') . ' GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$recent = q('SELECT id, title, status, published_at, date_label, updated_at FROM ' . T('posts') . ' ORDER BY updated_at DESC LIMIT 5')->fetchAll();
$proj = q('SELECT visibility, COUNT(*) n FROM ' . T('projects') . ' GROUP BY visibility')->fetchAll(PDO::FETCH_KEY_PAIR);
$views = (int)q('SELECT COALESCE(SUM(views), 0) FROM ' . T('projects'))->fetchColumn();
$fails = (int)q('SELECT COUNT(*) FROM ' . T('attempts') . " WHERE kind IN ('login', 'totp') AND ok = 0 AND created_at >= ?", [lab_now(-7 * 86400)])->fetchColumn();
$sessions = (int)q('SELECT COUNT(*) FROM ' . T('sessions') . ' WHERE user_id = ? AND mfa_pending = 0', [$uid])->fetchColumn();
$sion = lab_sionika_current();
$stats = lab_sionika_stats();
$week = 0;
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $week += isset($stats[$d]) ? array_sum($stats[$d]) : 0;
}

$warn = [];
if (!(int)$user['totp_enabled']) $warn[] = 'Вход по коду из приложения выключен. <a href="security.php#twofa">Включить</a> — тогда одного пароля для входа будет мало.';
if (!lab_is_https()) $warn[] = 'Панель открыта без HTTPS: пароль идёт по сети открытым текстом. Включите сертификат для сайта и верните require_https в config.php.';
if ($fails >= 10) $warn[] = 'За неделю ' . $fails . ' неудачных попыток входа. <a href="security.php#sessions">Проверьте журнал</a>.';

admin_page_start('Обзор', 'home', $s);
?>
<div class="head">
  <div><h1>Обзор</h1><p class="sub">ByteBunch • Lab · <?= h(lab_host()) ?></p></div>
  <div class="acts"><a class="btn" href="post.php">Новый пост</a><a class="btn line" href="projects.php">Загрузить проект</a></div>
</div>
<?php admin_flash(); ?>
<?php foreach ($warn as $w): ?><div class="notice warn"><p><?= $w ?></p></div><?php endforeach; ?>

<div class="cards">
  <div class="card stat"><span class="k">Посты</span><span class="v"><?= (int)($posts['published'] ?? 0) ?><small>опубликовано</small></span>
    <p><?= (int)($posts['draft'] ?? 0) ?> в черновиках · <a href="posts.php">все посты</a></p></div>
  <div class="card stat"><span class="k">HTML-проекты</span><span class="v"><?= array_sum($proj) ?></span>
    <p><?= (int)($proj['public'] ?? 0) ?> всем · <?= (int)($proj['key'] ?? 0) ?> по ключу · <?= (int)($proj['private'] ?? 0) ?> только мне · <?= $views ?> откр.</p></div>
  <div class="card stat"><span class="k">Sionika</span><span class="v"><?= h($sion['versionName'] ?: '—') ?><small>сборка <?= (int)$sion['versionCode'] ?></small></span>
    <p><?= $week ?> запусков за 7 дней · <a href="sionika.php">выпустить версию</a></p></div>
  <div class="card stat"><span class="k">Защита</span><span class="v"><?= (int)$user['totp_enabled'] ? 'пароль + код' : 'пароль' ?></span>
    <p><?= $sessions ?> активн. сесси<?= $sessions === 1 ? 'я' : 'и' ?> · <?= $fails ?> неудачных входов за неделю</p></div>
</div>

<section class="card">
  <h2 class="sec">Недавно менялись</h2>
  <ul class="rows">
    <?php foreach ($recent as $p): [$date] = lab_post_date($p); ?>
    <li><div class="t"><a class="name" href="post.php?id=<?= (int)$p['id'] ?>"><?= h(md_title_plain($p['title'])) ?></a>
      <div class="meta"><span class="em"><?= h($date) ?></span><?= admin_badge($p['status']) ?><span>изменён <?= h(lab_local($p['updated_at'])) ?></span></div></div>
      <div class="r"><a class="btn sm line" href="post.php?id=<?= (int)$p['id'] ?>">Изменить</a></div></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php admin_page_end();
