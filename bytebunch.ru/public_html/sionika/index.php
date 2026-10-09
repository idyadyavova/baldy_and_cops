<?php
/**
 * sionika.bytebunch.ru — проверка обновлений приложения Sionika.
 *
 * Приложение представляется заголовком User-Agent «Sionika/<номер сборки> (...)».
 * Ему отвечаем JSON с последней версией, всех остальных (браузеры) отправляем
 * на страницу проекта.
 *
 * Как выпустить обновление:
 *   1. Поднять versionCode (и versionName) в app/build.gradle.kts и собрать релиз.
 *   2. Разослать APK.
 *   3. В панели лаборатории (lab.bytebunch.ru/admin/ → Sionika) вписать те же versionCode
 *      и versionName — с этого момента у всех, у кого сборка старее, при запуске появится
 *      предложение скачать новую. Панель хранит выпуск в private/lab/data/sionika-release.json.
 *      Значения ниже действуют, пока из панели ничего не публиковали (или если файл повреждён).
 *
 * Проверить из браузера, что получает приложение: https://sionika.bytebunch.ru/?json
 * Статистика запросов по странам:                https://sionika.bytebunch.ru/?stats=<ключ>
 */

$latest = array(
    // Номер сборки (versionCode). Приложение предлагает обновиться, если он больше,
    // чем у установленной версии.
    'versionCode' => 20,

    // Название версии для людей (versionName).
    'versionName' => '3.0 beta',

    // Что нового, по пункту на строку. Можно оставить пустым: ''.
    'changes'     => "Новый тюнер на движке Aria\nКнопка «Убрать все» в выборе языков\nПроверка обновлений",

    // Куда ведёт кнопка «Скачать». Приложение откроет только t.me/ByteBunch
    // или страницу на *.bytebunch.ru, любой другой адрес заменит на t.me/ByteBunch.
    'url'         => 'https://t.me/ByteBunch',
);

// Выпуск из панели лаборатории главнее значений выше. Берём его, только если все поля на месте.
$panelRelease = __DIR__ . '/../../private/lab/data/sionika-release.json';
if (is_file($panelRelease)) {
    $fromPanel = json_decode((string)@file_get_contents($panelRelease), true);
    if (is_array($fromPanel)
        && isset($fromPanel['versionCode'], $fromPanel['versionName'], $fromPanel['changes'], $fromPanel['url'])
        && is_int($fromPanel['versionCode']) && $fromPanel['versionCode'] > 0
        && is_string($fromPanel['versionName']) && is_string($fromPanel['changes']) && is_string($fromPanel['url'])) {
        $latest = array(
            'versionCode' => $fromPanel['versionCode'],
            'versionName' => $fromPanel['versionName'],
            'changes'     => $fromPanel['changes'],
            'url'         => $fromPanel['url'],
        );
    }
}

$browserPage = 'http://lab.bytebunch.ru/sionika/';

// Ключ к странице статистики лежит в private/config.php (константа SIONIKA_STATS_KEY), а не здесь:
// так его не видно, даже если код сайта окажется в открытом репозитории.
// Пока ключа нет, страница статистики выключена. Статистику также показывает панель лаборатории.
$statsKey = '';
if (is_file(__DIR__ . '/../../private/config.php')) {
    require_once __DIR__ . '/../../private/config.php';
}
if (defined('SIONIKA_STATS_KEY')) {
    $statsKey = (string)SIONIKA_STATS_KEY;
}

// По какому времени считать дни в статистике.
date_default_timezone_set('Asia/Irkutsk');

// Счётчики «день → страна → запросов». IP-адреса не сохраняются. Файл с расширением .php
// и exit в первой строке: если открыть его в браузере, сервер ничего не покажет.
$statsFile = __DIR__ . '/stats-data.php';
define('STATS_GUARD', "<?php exit; ?>\n");

// ─────────────────────────────────────────────────────────────────────────────

$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$isApp = strpos($userAgent, 'Sionika/') === 0;

// Ответ зависит от User-Agent: кэш хостинга или браузера не должен их перепутать.
header('Vary: User-Agent');
header('Cache-Control: no-store');

if (isset($_GET['stats'])) {
    $given = is_string($_GET['stats']) ? $_GET['stats'] : '';
    if ($statsKey === '' || !hash_equals($statsKey, $given)) {
        http_response_code(404);
        exit;
    }
    header('X-Robots-Tag: noindex');
    showStats($statsFile);
    exit;
}

if (!$isApp && !isset($_GET['json'])) {
    header('Location: ' . $browserPage, true, 302);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($latest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($isApp) {
    // Сначала отдаём ответ, потом считаем: статистика не должна задерживать приложение.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    // Страну присылает приложение: по сотовой сети, SIM или настройкам телефона.
    // По IP было бы хуже: с включённым VPN все «приезжали» бы из Нидерландов.
    $country = isset($_SERVER['HTTP_X_SIONIKA_COUNTRY'])
        ? strtoupper(trim($_SERVER['HTTP_X_SIONIKA_COUNTRY'])) : '';
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        $country = '??';
    }
    countRequest($statsFile, date('Y-m-d'), $country);
}

// ─────────────────────────────────────────────────────────────────────────────

function readStats($handle)
{
    rewind($handle);
    $data = json_decode(substr(stream_get_contents($handle), strlen(STATS_GUARD)), true);
    return is_array($data) ? $data : array();
}

function countRequest($file, $day, $country)
{
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return; // нет прав на запись — приложение всё равно получило ответ
    }
    if (flock($handle, LOCK_EX)) {
        $stats = readStats($handle);
        if (!isset($stats[$day][$country])) {
            $stats[$day][$country] = 0;
        }
        $stats[$day][$country]++;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, STATS_GUARD . json_encode($stats));
        fflush($handle);
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

function countryName($code)
{
    if ($code === '??') {
        return 'не определена';
    }
    if (class_exists('Locale')) {
        $name = Locale::getDisplayRegion('-' . $code, 'ru');
        if ($name !== '' && $name !== $code) {
            return $name . ' (' . $code . ')';
        }
    }
    return $code;
}

function formatCounts($counts)
{
    arsort($counts);
    $parts = array();
    foreach ($counts as $code => $n) {
        $parts[] = htmlspecialchars(countryName($code)) . ' — ' . $n;
    }
    return implode(', ', $parts);
}

function showStats($file)
{
    $stats = array();
    $handle = @fopen($file, 'r');
    if ($handle) {
        flock($handle, LOCK_SH);
        $stats = readStats($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    krsort($stats);

    $total = 0;
    $byCountry = array();
    foreach ($stats as $counts) {
        foreach ($counts as $code => $n) {
            $byCountry[$code] = (isset($byCountry[$code]) ? $byCountry[$code] : 0) + $n;
            $total += $n;
        }
    }
    arsort($byCountry);

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sionika — статистика</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; margin: 24px auto; max-width: 760px; padding: 0 16px; color: #1b1f24; background: #fff; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 28px 0 8px; }
  .muted { color: #667; }
  table { border-collapse: collapse; width: 100%; }
  th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
  th { font-weight: 600; }
  td.n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  @media (prefers-color-scheme: dark) {
    body { color: #e6e8eb; background: #15181c; }
    .muted { color: #9aa3ad; }
    th, td { border-color: #2a2f36; }
  }
</style>
</head>
<body>
<h1>Sionika — статистика</h1>
<p class="muted">Запрос — это запуск приложения с проверкой обновлений. Один человек может дать несколько запросов в день. Дни считаются по иркутскому времени.</p>

<?php if ($total === 0) { ?>
<p>Запросов пока не было.</p>
<?php } else { ?>
<h2>За всё время: <?php echo $total; ?></h2>
<table>
  <tr><th>Страна</th><th class="n">Запросов</th></tr>
  <?php foreach ($byCountry as $code => $n) { ?>
  <tr><td><?php echo htmlspecialchars(countryName($code)); ?></td><td class="n"><?php echo $n; ?></td></tr>
  <?php } ?>
</table>

<h2>По дням</h2>
<table>
  <tr><th>Дата</th><th class="n">Всего</th><th>Страны</th></tr>
  <?php foreach ($stats as $day => $counts) { ?>
  <tr>
    <td style="white-space:nowrap"><?php echo htmlspecialchars(date('d.m.Y', strtotime($day))); ?></td>
    <td class="n"><?php echo array_sum($counts); ?></td>
    <td><?php echo formatCounts($counts); ?></td>
  </tr>
  <?php } ?>
</table>
<?php } ?>
</body>
</html>
<?php
}
