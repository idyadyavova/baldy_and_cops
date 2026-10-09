<?php
// Общее для страниц панели: заголовки безопасности, проверка входа, оформление.

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/schema.php';

function admin_headers(): void
{
    header_remove('X-Powered-By');
    header('Content-Type: text/html; charset=utf-8');
    // скрипты, стили и картинки — только свои; встроить панель в чужой сайт нельзя
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (lab_is_https()) header('Strict-Transport-Security: max-age=15552000');
}

/** Без HTTPS панель не открывается (если это не выключено в config.php). */
function admin_require_https(): void
{
    $c = lab_config();
    if (lab_is_https() || ($c && empty($c['require_https']))) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        lab_redirect('https://' . lab_host() . (string)($_SERVER['REQUEST_URI'] ?? '/'), 302);
    }
    http_response_code(403);
    exit('Панель работает только по HTTPS.');
}

/** Начало каждой страницы панели. Возвращает сессию; без входа отправляет на страницу входа. */
function admin_boot(bool $needLogin = true): ?array
{
    admin_headers();
    admin_require_https();
    $state = lab_install_state();
    if ($state !== 'ready') {
        if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'setup.php') lab_redirect('setup.php');
        return null;
    }
    if (!$needLogin) return lab_session();
    $s = lab_session();
    $ajax = isset($_SERVER['HTTP_X_LAB_AJAX']);
    if (!$s && $ajax) lab_json(['error' => 'Сессия закончилась. Войдите заново в соседней вкладке и повторите.'], 401);
    if ($s && $ajax && is_post() && !lab_check_csrf($s)) lab_json(['error' => 'Страница устарела. Обновите её и повторите.'], 400);
    if (!$s) {
        $back = (string)($_SERVER['REQUEST_URI'] ?? '');
        lab_redirect('login.php' . ($back !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? '?next=' . rawurlencode($back) : ''));
    }
    if (is_post() && !lab_check_csrf($s)) {
        http_response_code(400);
        admin_page_start('Форма устарела', '', $s);
        echo '<div class="notice err">Форма устарела или пришла с другого сайта. Вернитесь назад, обновите страницу и повторите.</div>';
        admin_page_end();
        exit;
    }
    return $s;
}

// ─── оформление ──────────────────────────────────────────────────────────────

function admin_nav_items(): array
{
    return [
        'home' => ['index.php', 'Обзор'],
        'posts' => ['posts.php', 'Посты'],
        'projects' => ['projects.php', 'Проекты'],
        'sionika' => ['sionika.php', 'Sionika'],
        'security' => ['security.php', 'Безопасность'],
    ];
}

function admin_page_start(string $title, string $active, ?array $s, bool $bare = false): void
{
    $base = lab_base();
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<meta name="robots" content="noindex, nofollow"><meta name="theme-color" content="#000000">';
    echo '<title>' . h($title) . ' — панель ByteBunch • Lab</title>';
    echo '<link rel="icon" href="' . h($base) . 'assets/favicon.svg" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="' . h($base) . 'assets/fonts.css">';
    echo '<link rel="stylesheet" href="assets/admin.css?v=1">';
    echo '</head><body class="' . ($bare ? 'bare' : 'app') . '">';
    if ($bare) {
        echo '<main class="solo"><a class="solo-brand" href="' . h($base) . '" aria-label="На сайт">' . lab_logo() . '<span>• Lab</span></a>';
        return;
    }
    echo '<header class="bar"><a class="brand" href="index.php" aria-label="Панель, обзор">' . lab_logo() . '<span>• Lab</span></a>';
    echo '<nav class="tabs" aria-label="Разделы панели">';
    foreach (admin_nav_items() as $k => [$href, $label]) {
        echo '<a href="' . $href . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    echo '</nav><div class="me">';
    echo '<a class="site" href="' . h($base) . '" target="_blank" rel="noopener">Сайт ↗</a>';
    if ($s) {
        echo '<form method="post" action="logout.php">' . lab_csrf_field($s) . '<button class="link" type="submit" title="Выйти из «' . h($s['login']) . '»">Выйти</button></form>';
    }
    echo '</div></header><main class="page">';
}

function admin_page_end(array $scripts = []): void
{
    echo '</main>';
    foreach ($scripts as $src) echo '<script src="' . h($src) . '"></script>';
    echo '<script src="assets/admin.js?v=1"></script></body></html>';
}

function admin_flash(): void
{
    $msgs = [
        'saved' => 'Сохранено.',
        'created' => 'Создано.',
        'deleted' => 'Удалено.',
        'uploaded' => 'Загружено.',
        'key' => 'Новый ключ создан. Старая ссылка больше не работает.',
        'pass' => 'Пароль изменён. Остальные сессии завершены.',
        '2fa_on' => 'Вход по коду включён.',
        '2fa_off' => 'Вход по коду выключен.',
        'ended' => 'Сессии завершены.',
        'revoked' => 'Ключ отозван.',
        'released' => 'Версия опубликована: приложения увидят её при следующей проверке.',
    ];
    $m = get_str('msg');
    if (isset($msgs[$m])) echo '<div class="notice ok" role="status">' . h($msgs[$m]) . '</div>';
}

function admin_errors(array $errors): void
{
    if (!$errors) return;
    echo '<div class="notice err" role="alert">';
    foreach ($errors as $e) echo '<p>' . h($e) . '</p>';
    echo '</div>';
}

function admin_badge(string $kind): string
{
    $map = [
        'published' => ['опубликован', 'pub'],
        'draft' => ['черновик', 'draft'],
        'public' => ['всем', 'pub'],
        'key' => ['по ключу', 'key'],
        'private' => ['только мне', 'priv'],
    ];
    [$t, $c] = $map[$kind] ?? [$kind, ''];
    return '<span class="badge ' . $c . '">' . h($t) . '</span>';
}

function admin_checked(bool $v): string
{
    return $v ? ' checked' : '';
}

function admin_selected(bool $v): string
{
    return $v ? ' selected' : '';
}
