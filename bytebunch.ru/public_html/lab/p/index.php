<?php
// Выдача HTML-проектов: p/<адрес>/…
//
//   открытый       p/<адрес>/                     — всем
//   по ключу       p/<адрес>/~<ключ>/             — тем, у кого есть ссылка с ключом
//   только мне     p/<адрес>/~<срок>-<подпись>/   — временная ссылка из панели, живёт 12 часов
//
// Ключ — часть пути, поэтому картинки, стили и скрипты проекта, подключённые относительными адресами, тоже его получают.
// Без верного ключа ответ — «404, такой страницы нет», как будто проекта не существует.
// Проект в «изоляции» (по умолчанию) открывается в песочнице: его скрипты не видят cookie и данные панели.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/projects.php';

header_remove('X-Powered-By');
$base = lab_base();

function p_404(): void
{
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Нет такой страницы</title>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#000;color:#b5b5b5;font:16px/1.5 system-ui,sans-serif">'
        . '<p>Такой страницы нет.</p></body>';
    exit;
}

// путь после p/: из PATH_INFO (p/index.php/…) или из адреса (через .htaccess)
$path = (string)($_SERVER['PATH_INFO'] ?? '');
if ($path === '') {
    $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $prefix = $base . 'p/';
    if (strpos($uri, $prefix) !== 0) p_404();
    $path = substr($uri, strlen($prefix));
}
$path = rawurldecode(ltrim($path, '/'));
if (strpos($path, "\0") !== false) p_404();
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
$qs = $query !== '' ? '?' . $query : '';

$db = lab_db_or_null();
if (!$db) p_404();

// ─── p/ — список открытых проектов ───────────────────────────────────────────
if ($path === '' || $path === 'index.php') {
    header('Content-Type: text/html; charset=utf-8');
    $list = lab_projects_listed();
    echo lab_site_head($base, 'Эксперименты — ByteBunch • Lab', 'Открытые HTML-проекты лаборатории ByteBunch.');
    echo lab_site_nav($base);
    echo '<main class="article-wrap"><section class="wrap"><div class="sec-head"><h2>Эксперименты</h2><p class="meta">HTML-проекты лаборатории</p></div><ul class="exp">';
    foreach ($list as $p) {
        echo '<li><a href="' . h($base . lab_project_path($p)) . '"><b>' . h($p['title']) . '</b><span class="ar">открыть →</span>'
            . ($p['description'] !== '' ? '<span>' . h($p['description']) . '</span>' : '') . '</a></li>';
    }
    if (!$list) echo '<li><a><span>Открытых проектов пока нет.</span></a></li>';
    echo '</ul></section></main>' . lab_site_footer($base) . '<script src="' . h($base) . 'assets/lab.js"></script></body></html>';
    exit;
}

// ─── проект ──────────────────────────────────────────────────────────────────
$parts = explode('/', $path, 2);
$slug = $parts[0];
if (!lab_valid_slug($slug)) p_404();
if (count($parts) === 1) lab_redirect($base . 'p/' . $slug . '/' . $qs, 301);   // без «/» в конце сломаются относительные ссылки
$rest = $parts[1];

$p = lab_project_by_slug($slug);
if (!$p) p_404();

// токен доступа — первый сегмент, если начинается с «~»
$token = '';
$tokenPath = '';
if ($rest !== '' && $rest[0] === '~') {
    $tp = explode('/', $rest, 2);
    $token = substr($tp[0], 1);
    if (count($tp) === 1) lab_redirect($base . 'p/' . $slug . '/' . $tp[0] . '/' . $qs, 301);
    $rest = $tp[1];
    $tokenPath = $tp[0] . '/';
}

$byKey = $token !== '' && preg_match('/^[A-Za-z0-9]{24,64}$/', $token) && hash_equals((string)$p['access_key'], $token);
$byTemp = false;
if ($token !== '' && preg_match('/^(\d{10})-([a-f0-9]{32})$/', $token, $m)) {
    $byTemp = (int)$m[1] > time() && hash_equals(lab_project_temp_sig($p, (int)$m[1]), $m[2]);
}
$allowed = ($p['visibility'] === 'public') || ($p['visibility'] === 'key' && ($byKey || $byTemp)) || ($p['visibility'] === 'private' && $byTemp);
if (!$allowed) p_404();

// ─── файл ────────────────────────────────────────────────────────────────────
$dir = lab_project_dir((int)$p['id']);
$isEntry = false;
if ($rest === '') {
    // главная страница в подпапке архива: переходим на её настоящий адрес, иначе сломаются её относительные ссылки
    if (strpos($p['entry'], '/') !== false) lab_redirect($base . 'p/' . $slug . '/' . $tokenPath . implode('/', array_map('rawurlencode', explode('/', $p['entry']))) . $qs, 302);
    $rest = $p['entry'];
    $isEntry = true;
} elseif ($rest === $p['entry']) {
    $isEntry = true;
} elseif (substr($rest, -1) === '/') {
    $rest .= 'index.html';
}
$rel = lab_project_clean_path($rest);
if ($rel === null) p_404();
$file = $dir . '/' . $rel;
$realDir = realpath($dir);
$real = realpath($file);
if ($realDir === false || $real === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0) p_404();
if (is_dir($real)) lab_redirect($base . 'p/' . $slug . '/' . $tokenPath . $rel . '/' . $qs, 301);
if (!is_file($real)) p_404();

$types = lab_project_types();
$ext = lab_project_ext($real);
$type = $types[$ext] ?? 'application/octet-stream';
$size = (int)filesize($real);
$mtime = (int)filemtime($real);
$etag = '"' . substr(sha1($p['id'] . '|' . $rel . '|' . $size . '|' . $mtime . '|' . $p['updated_at']), 0, 20) . '"';

header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');                // ключ из адреса не уходит на чужие сайты
header('X-Frame-Options: SAMEORIGIN');                 // встроить проект можно только в посты этого сайта
if ((int)$p['sandbox'] === 1) {
    // песочница: у страницы проекта «чужое» происхождение — ни cookie сайта, ни доступа к панели
    header("Content-Security-Policy: sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-pointer-lock allow-presentation; frame-ancestors 'self'");
    header('Access-Control-Allow-Origin: *');          // чтобы fetch() внутри песочницы мог читать файлы своего же проекта
} else {
    header("Content-Security-Policy: frame-ancestors 'self'");
}
if ($p['visibility'] !== 'public') header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: ' . ($p['visibility'] === 'public' ? 'public' : 'private') . ', max-age=' . ($isEntry || $ext === 'html' ? '60' : '600'));
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('Accept-Ranges: bytes');
if (!isset($types[$ext])) header('Content-Disposition: attachment');

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

if ($isEntry && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        q('UPDATE ' . T('projects') . ' SET views = views + 1 WHERE id = ?', [$p['id']]);
    } catch (Throwable $e) {
    }
}

// диапазоны — чтобы перематывались аудио и видео
$start = 0;
$end = $size - 1;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min($end, (int)$m[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Length: ' . ($end - $start + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;

$fh = fopen($real, 'rb');
if ($start > 0) fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, (int)min(262144, $left));
    if ($chunk === false) break;
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);
