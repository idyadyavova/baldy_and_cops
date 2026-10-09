<?php
// API лаборатории для скриптов: загрузить HTML-проект или опубликовать пост без входа в панель.
// Ключ создаётся в панели: «Безопасность» → «API-ключи». Передаётся заголовком Authorization: Bearer bbk_…
//
//   GET  api/?do=ping                       проверить ключ
//   GET  api/?do=projects                   список проектов              (нужно право «проекты»)
//   POST api/?do=project  (multipart)       создать или обновить проект: slug, file, title, description,
//                                           visibility=public|key|private, sandbox=0|1, listed=0|1
//   GET  api/?do=posts                      список постов                (нужно право «посты»)
//   POST api/?do=post     (JSON или форма)  создать или изменить пост по slug: title, tag, summary, body,
//                                           link, status=draft|published, published_at, date_label, featured, cover
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/content.php';
require __DIR__ . '/../../../private/lab/app/auth.php';
require __DIR__ . '/../../../private/lab/app/projects.php';

header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function api_fail(string $msg, int $code): void
{
    lab_json(['error' => $msg], $code);
}

if (!lab_db_or_null()) api_fail('Панель не установлена', 503);
$cfg = lab_config();
if (!lab_is_https() && !empty($cfg['require_https'])) api_fail('Только HTTPS', 403);

// ─── ключ ────────────────────────────────────────────────────────────────────
if (($wait = lab_throttled('api')) > 0) {
    header('Retry-After: ' . $wait);
    api_fail('Слишком много неверных ключей. Повторите через ' . ceil($wait / 60) . ' мин.', 429);
}
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
$key = null;
if (preg_match('/^bbk_[a-z0-9]{8}_[A-Za-z0-9]{40}$/', $token)) {
    $key = q('SELECT * FROM ' . T('api_keys') . ' WHERE key_hash = ? AND revoked_at IS NULL', [hash('sha256', $token)])->fetch();
}
if (!$key) {
    lab_attempt('api', '', false);
    api_fail('Неверный или отозванный ключ', 401);
}
if (!$key['last_used_at'] || strtotime($key['last_used_at'] . ' UTC') < time() - 60) {
    q('UPDATE ' . T('api_keys') . ' SET last_used_at = ? WHERE id = ?', [lab_now(), $key['id']]);
}
$scopes = explode(',', $key['scopes']);
$uid = (int)$key['user_id'];
$who = 'API «' . $key['name'] . '»: ';
$need = function (string $scope) use ($scopes) {
    if (!in_array($scope, $scopes, true)) api_fail('У ключа нет права «' . ($scope === 'posts' ? 'посты' : 'проекты') . '»', 403);
};
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$do = get_str('do');

if ($do === 'ping') lab_json(['ok' => true, 'key' => $key['name'], 'scopes' => $scopes]);

// ─── проекты ─────────────────────────────────────────────────────────────────
function api_project_out(array $p): array
{
    return [
        'slug' => $p['slug'], 'title' => $p['title'], 'visibility' => $p['visibility'], 'sandbox' => (bool)$p['sandbox'], 'listed' => (bool)$p['listed'],
        'files' => (int)$p['files_count'], 'bytes' => (int)$p['size_bytes'], 'views' => (int)$p['views'],
        'url' => $p['visibility'] === 'public' ? lab_abs_url(lab_project_path($p)) : null,
        'key_url' => $p['visibility'] === 'key' ? lab_abs_url(lab_project_path($p, 'key')) : null,
        'updated_at' => $p['updated_at'],
    ];
}

if ($do === 'projects' && $method === 'GET') {
    $need('projects');
    lab_json(['projects' => array_map('api_project_out', q('SELECT * FROM ' . T('projects') . ' ORDER BY updated_at DESC')->fetchAll())]);
}

if ($do === 'project' && $method === 'POST') {
    $need('projects');
    if (lab_post_too_big()) api_fail('Файл больше, чем разрешает хостинг (' . lab_bytes(lab_upload_limit()) . ')', 413);
    $slug = strtolower(trim(post_str('slug', 100)));
    if (!lab_valid_slug($slug)) api_fail('slug: латиница, цифры и дефисы', 400);
    $p = lab_project_by_slug($slug);
    $hasFile = isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$p && !$hasFile) api_fail('Нового проекта без файла не бывает: передайте file', 400);

    $vis = post_str('visibility', 12);
    $fields = [
        'title' => isset($_POST['title']) ? trim(post_str('title', 200)) : ($p['title'] ?? $slug),
        'description' => isset($_POST['description']) ? trim(post_str('description', 1000)) : ($p['description'] ?? ''),
        'visibility' => in_array($vis, ['public', 'key', 'private'], true) ? $vis : ($p['visibility'] ?? 'key'),
        'sandbox' => isset($_POST['sandbox']) ? (post_str('sandbox', 5) === '0' ? 0 : 1) : (int)($p['sandbox'] ?? 1),
        'listed' => isset($_POST['listed']) ? (post_str('listed', 5) === '1' ? 1 : 0) : (int)($p['listed'] ?? 0),
    ];
    if ($fields['title'] === '') $fields['title'] = $slug;
    if ($fields['visibility'] !== 'public') $fields['listed'] = 0;

    $now = lab_now();
    $created = false;
    if (!$p) {
        q('INSERT INTO ' . T('projects') . ' (slug, title, description, visibility, access_key, entry, sandbox, listed, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'index.html\', ?, ?, ?, ?)',
            [$slug, $fields['title'], $fields['description'], $fields['visibility'], lab_key(), $fields['sandbox'], $fields['listed'], $now, $now]);
        $id = (int)lab_db()->lastInsertId();
        $created = true;
    } else {
        $id = (int)$p['id'];
        q('UPDATE ' . T('projects') . ' SET title = ?, description = ?, visibility = ?, sandbox = ?, listed = ?, updated_at = ? WHERE id = ?',
            [$fields['title'], $fields['description'], $fields['visibility'], $fields['sandbox'], $fields['listed'], $now, $id]);
    }
    $skipped = [];
    if ($hasFile) {
        try {
            $r = lab_project_store($id, $_FILES['file']);
            $skipped = $r['skipped'];
            q('UPDATE ' . T('projects') . ' SET entry = ?, size_bytes = ?, files_count = ? WHERE id = ?', [$r['entry'], $r['size'], $r['count'], $id]);
        } catch (RuntimeException $e) {
            if ($created) q('DELETE FROM ' . T('projects') . ' WHERE id = ?', [$id]);
            api_fail($e->getMessage(), 400);
        }
    }
    lab_audit($uid, $created ? 'project_create' : 'project_upload', $who . '«' . $fields['title'] . '» (' . $slug . ')');
    $out = api_project_out(q('SELECT * FROM ' . T('projects') . ' WHERE id = ?', [$id])->fetch());
    lab_json(['ok' => true, 'created' => $created, 'skipped' => $skipped, 'project' => $out], $created ? 201 : 200);
}

// ─── посты ───────────────────────────────────────────────────────────────────
function api_post_out(array $p): array
{
    return [
        'slug' => $p['slug'], 'title' => $p['title'], 'tag' => $p['tag'], 'summary' => $p['summary'], 'body' => $p['body'], 'link' => $p['link'],
        'cover' => $p['cover'], 'status' => $p['status'], 'featured' => (bool)$p['featured'], 'date_label' => $p['date_label'],
        'published_at' => $p['published_at'], 'url' => lab_post_href($p, lab_abs_url()), 'updated_at' => $p['updated_at'],
    ];
}

if ($do === 'posts' && $method === 'GET') {
    $need('posts');
    lab_json(['posts' => array_map('api_post_out', q('SELECT * FROM ' . T('posts') . ' ORDER BY published_at DESC, id DESC')->fetchAll())]);
}

if ($do === 'post' && $method === 'POST') {
    $need('posts');
    $in = $_POST;
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 2000000), true);
        if (!is_array($in)) api_fail('Тело запроса — не JSON-объект', 400);
    }
    $str = function (string $k, int $max) use ($in): ?string {
        if (!array_key_exists($k, $in)) return null;
        if (!is_scalar($in[$k])) api_fail($k . ': ожидается строка', 400);
        $v = str_replace("\r\n", "\n", (string)$in[$k]);
        return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
    };
    $slug = strtolower(trim((string)$str('slug', 100)));
    $p = $slug !== '' ? lab_post_by_slug($slug) : null;
    $title = $str('title', 200);
    if (!$p && ($title === null || trim($title) === '')) api_fail('Для нового поста нужен title', 400);
    if ($slug === '') $slug = lab_slugify(md_title_plain((string)$title));
    if (!lab_valid_slug($slug)) api_fail('slug: латиница, цифры и дефисы', 400);

    $cur = $p ?: ['title' => '', 'tag' => '', 'summary' => '', 'body' => '', 'link' => '', 'cover' => '', 'cover_caption' => '', 'status' => 'draft', 'featured' => 0, 'date_label' => '', 'published_at' => date('Y-m-d')];
    $v = [];
    foreach (['title' => 200, 'tag' => 40, 'summary' => 1000, 'body' => 400000, 'link' => 500, 'cover' => 200, 'cover_caption' => 300, 'date_label' => 40, 'published_at' => 10] as $k => $max) {
        $x = $str($k, $max);
        $v[$k] = $x === null ? $cur[$k] : trim($x);
    }
    $st = $str('status', 12);
    $v['status'] = $st === null ? $cur['status'] : ($st === 'published' ? 'published' : 'draft');
    $v['featured'] = array_key_exists('featured', $in) ? (filter_var($in['featured'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : (int)$cur['featured'];
    $d = DateTime::createFromFormat('!Y-m-d', $v['published_at']);
    if (!$d || $d->format('Y-m-d') !== $v['published_at']) api_fail('published_at: ГГГГ-ММ-ДД', 400);
    if ($v['link'] !== '' && md_url($v['link']) === null) api_fail('link: https://… или адрес на сайте', 400);
    if ($v['cover'] !== '' && $v['cover'] !== 'aria-dial' && md_url($v['cover'], true) === null) api_fail('cover: https://… или media/…', 400);

    $now = lab_now();
    if ($v['featured']) q('UPDATE ' . T('posts') . ' SET featured = 0 WHERE slug <> ?', [$slug]);
    $vals = [$v['title'], $v['tag'], $v['summary'], $v['body'], $v['link'], $v['cover'], $v['cover_caption'], $v['status'], $v['featured'], $v['date_label'], $v['published_at']];
    if ($p) {
        q('UPDATE ' . T('posts') . ' SET title = ?, tag = ?, summary = ?, body = ?, link = ?, cover = ?, cover_caption = ?, status = ?, featured = ?, date_label = ?, published_at = ?, updated_at = ? WHERE id = ?',
            array_merge($vals, [$now, $p['id']]));
    } else {
        q('INSERT INTO ' . T('posts') . ' (title, tag, summary, body, link, cover, cover_caption, status, featured, date_label, published_at, slug, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge($vals, [$slug, $now, $now]));
    }
    lab_audit($uid, $p ? 'post_save' : 'post_create', $who . '«' . md_title_plain($v['title']) . '» — ' . $v['status']);
    lab_json(['ok' => true, 'created' => !$p, 'post' => api_post_out(lab_post_by_slug($slug))], $p ? 200 : 201);
}

api_fail('Неизвестный запрос. Смотрите описание в начале api/index.php', 404);
