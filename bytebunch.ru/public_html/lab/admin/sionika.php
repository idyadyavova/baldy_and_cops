<?php
// Sionika: какую версию предлагать приложениям и сколько раз их запускали.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';
require __DIR__ . '/../../../private/lab/app/sionika.php';

$s = admin_boot();
$uid = (int)$s['user_id'];
$cur = lab_sionika_current();
$errors = [];
$form = $cur;

if (is_post()) {
    $form = [
        'versionCode' => trim(post_str('versionCode', 12)),
        'versionName' => trim(post_str('versionName', 60)),
        'changes' => trim(preg_replace("/\n{2,}/", "\n", post_str('changes', 3000))),
        'url' => trim(post_str('url', 300)),
    ];
    if (!preg_match('/^\d{1,9}$/', $form['versionCode']) || (int)$form['versionCode'] < 1) $errors[] = 'Номер сборки (versionCode) — целое число больше нуля.';
    if ($form['versionName'] === '') $errors[] = 'Нужно название версии, например «3.0».';
    if (!lab_sionika_url_ok($form['url'])) $errors[] = 'Приложение откроет только https://t.me/ByteBunch… или страницу на *.bytebunch.ru.';
    if (!$errors) {
        $form['versionCode'] = (int)$form['versionCode'];
        if (lab_sionika_publish($form)) {
            lab_audit($uid, 'sionika_release', $form['versionName'] . ' (сборка ' . $form['versionCode'] . ')');
            lab_redirect('sionika.php?msg=released');
        }
        $errors[] = 'Не получилось записать private/lab/data/sionika-release.json — проверьте права на папку.';
    }
}

$stats = lab_sionika_stats();
$total = 0;
$byCountry = [];
foreach ($stats as $day => $counts) {
    foreach ($counts as $code => $n) {
        $byCountry[$code] = ($byCountry[$code] ?? 0) + $n;
        $total += $n;
    }
}
arsort($byCountry);
$days = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days[$d] = isset($stats[$d]) ? array_sum($stats[$d]) : 0;
}
$max = max(1, max($days));
$week = array_sum(array_slice($days, -7));

admin_page_start('Sionika', 'sionika', $s);
?>
<div class="head">
  <div><h1>Sionika</h1><p class="sub">проверка обновлений · sionika.bytebunch.ru</p></div>
</div>
<?php admin_flash(); admin_errors($errors); ?>

<div class="cards">
  <div class="card stat"><span class="k">Версия для приложений</span><span class="v"><?= h($cur['versionName'] ?: '—') ?><small>сборка <?= (int)$cur['versionCode'] ?></small></span>
    <p><?= $cur['source'] === 'panel' ? 'опубликована из панели ' . h(lab_local(isset($cur['updated_at']) ? gmdate('Y-m-d H:i:s', strtotime($cur['updated_at'])) : null)) : 'из файла sionika/index.php' ?></p></div>
  <div class="card stat"><span class="k">Запусков за 7 дней</span><span class="v"><?= $week ?></span><p>за всё время — <?= $total ?></p></div>
  <div class="card stat"><span class="k">Стран</span><span class="v"><?= count(array_diff_key($byCountry, ['??' => 1])) ?></span><p><?= $byCountry ? h(lab_country_name((string)array_key_first($byCountry))) . ' — чаще всего' : 'данных пока нет' ?></p></div>
</div>

<section class="card">
  <h2 class="sec">Выпустить версию</h2>
  <p class="hint">Сначала разошлите APK. Как только сохраните здесь номер сборки больше, чем у человека в телефоне, приложение при запуске предложит обновиться.
    Проверить ответ: <a href="https://sionika.bytebunch.ru/?json" target="_blank" rel="noopener">sionika.bytebunch.ru/?json</a></p>
  <form method="post" class="form" data-confirm="Опубликовать версию? Все приложения со сборкой старее увидят предложение обновиться.">
    <?= lab_csrf_field($s) ?>
    <div class="grid2">
      <label class="field"><span>Номер сборки <small>versionCode из app/build.gradle.kts</small></span><input type="number" name="versionCode" min="1" step="1" value="<?= h((string)$form['versionCode']) ?>" required></label>
      <label class="field"><span>Название версии <small>versionName, например «3.0»</small></span><input type="text" name="versionName" value="<?= h($form['versionName']) ?>" required maxlength="60"></label>
    </div>
    <label class="field"><span>Что нового <small>по пункту на строку</small></span><textarea name="changes" rows="5" maxlength="3000"><?= h($form['changes']) ?></textarea></label>
    <label class="field"><span>Куда ведёт «Скачать» <small>t.me/ByteBunch или страница на *.bytebunch.ru</small></span><input type="url" name="url" value="<?= h($form['url']) ?>" required maxlength="300"></label>
    <div class="actions"><button class="btn" type="submit">Опубликовать</button></div>
  </form>
</section>

<section class="card">
  <h2 class="sec">Запуски за 30 дней</h2>
  <p class="hint">Запуск — это проверка обновлений при старте приложения. Один человек может запускать несколько раз в день. IP-адреса не хранятся.</p>
  <div class="bars">
    <?php foreach ($days as $d => $n): ?>
      <div class="b"><span><?= h(date('d.m', strtotime($d))) ?></span><i data-w="<?= round($n / $max * 100, 1) ?>"></i><span><?= $n ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2 class="sec">Страны за всё время</h2>
  <div class="scroll-x"><table class="t">
    <tr><th>Страна</th><th class="n">Запусков</th></tr>
    <?php foreach ($byCountry as $code => $n): ?><tr><td><?= h(lab_country_name((string)$code)) ?></td><td class="n"><?= (int)$n ?></td></tr><?php endforeach; ?>
    <?php if (!$byCountry): ?><tr><td colspan="2" class="hint">Данных пока нет.</td></tr><?php endif; ?>
  </table></div>
</section>
<?php admin_page_end();
