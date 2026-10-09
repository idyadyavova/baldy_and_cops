<?php
// Посты и проекты для публичных страниц сайта.

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/markdown.php';

const LAB_TAGS = ['Релиз', 'Исследование', 'Анонс', 'Заметка'];

function lab_posts_published(int $limit = 50, int $offset = 0): array
{
    return q('SELECT * FROM ' . T('posts') . " WHERE status = 'published' ORDER BY published_at DESC, id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset)->fetchAll();
}

function lab_post_featured(): ?array
{
    $p = q('SELECT * FROM ' . T('posts') . " WHERE status = 'published' AND featured = 1 ORDER BY published_at DESC, id DESC LIMIT 1")->fetch();
    return $p ?: null;
}

function lab_post_by_slug(string $slug): ?array
{
    $p = q('SELECT * FROM ' . T('posts') . ' WHERE slug = ?', [$slug])->fetch();
    return $p ?: null;
}

/** Куда ведёт пост: на свою страницу, если есть текст; на указанный адрес; или никуда («скоро»). */
function lab_post_href(array $p, string $base): ?string
{
    if (trim((string)$p['body']) !== '') return $base . 'news/' . $p['slug'];
    if ($p['link'] !== '') {
        $u = md_url($p['link']);
        return $u === null ? null : md_href($u, $base);
    }
    return null;
}

function lab_post_date(array $p): array
{
    // [видимый текст, значение для <time datetime>]
    $iso = (string)$p['published_at'];
    if ($p['date_label'] !== '') {
        $dt = preg_match('/^\d{2}\.\d{4}$/', $p['date_label']) ? substr($iso, 0, 7) : '';
        return [$p['date_label'], $dt];
    }
    $t = strtotime($iso);
    return [$t ? date('d.m.Y', $t) : $iso, $iso];
}

function lab_post_time_html(array $p): string
{
    [$txt, $dt] = lab_post_date($p);
    return '<time' . ($dt !== '' ? ' datetime="' . h($dt) . '"' : '') . '>' . h($txt) . '</time>';
}

/** Картинка обложки: файл из media/ или адрес. 'aria-dial' — особая анимированная шкала главной. */
function lab_cover_src(string $cover, string $base): ?string
{
    if ($cover === '' || $cover === 'aria-dial') return null;
    $u = md_url($cover, true);
    return $u === null ? null : md_href($u, $base);
}

// ─── проекты ─────────────────────────────────────────────────────────────────

function lab_project_by_slug(string $slug): ?array
{
    $p = q('SELECT * FROM ' . T('projects') . ' WHERE slug = ?', [$slug])->fetch();
    return $p ?: null;
}

function lab_projects_listed(): array
{
    return q('SELECT * FROM ' . T('projects') . " WHERE visibility = 'public' AND listed = 1 ORDER BY updated_at DESC")->fetchAll();
}

/** Ссылка на проект. Для «по ключу» ключ — часть адреса, поэтому картинки и скрипты проекта тоже его получают. */
function lab_project_path(array $p, string $mode = ''): string
{
    $path = 'p/' . $p['slug'] . '/';
    if ($mode === 'key') return $path . '~' . $p['access_key'] . '/';
    if ($mode === 'temp') {
        $exp = time() + 12 * 3600;
        return $path . '~' . $exp . '-' . lab_project_temp_sig($p, $exp) . '/';
    }
    return $path;
}

/** Временная ссылка для закрытого проекта: подпись HMAC, живёт 12 часов, перестаёт работать при смене ключа. */
function lab_project_temp_sig(array $p, int $exp): string
{
    return substr(hash_hmac('sha256', 'tmp|' . $p['id'] . '|' . $p['access_key'] . '|' . $exp, lab_secret()), 0, 32);
}

function lab_project_embed_html(string $slug, string $base): string
{
    $p = lab_project_by_slug($slug);
    if (!$p || $p['visibility'] !== 'public') return '';
    $sandbox = (int)$p['sandbox'] === 1 ? ' sandbox="allow-scripts allow-forms allow-popups allow-modals allow-downloads allow-pointer-lock"' : '';
    return '<figure class="embed"><iframe src="' . h($base . lab_project_path($p)) . '" title="' . h($p['title']) . '" loading="lazy"' . $sandbox
        . ' allow="fullscreen"></iframe><figcaption><a href="' . h($base . lab_project_path($p)) . '" target="_blank" rel="noopener">'
        . h($p['title']) . ' — открыть отдельно ↗</a></figcaption></figure>' . "\n";
}

// ─── общие куски страниц ─────────────────────────────────────────────────────

/** Шапка сайта — та же, что на главной, Sionika и Aria. $base — путь к корню лаборатории. */
function lab_site_nav(string $base, string $current = ''): string
{
    $a = function ($href, $label, $key, $cls = '') use ($base, $current) {
        return '<a' . ($cls ? ' class="' . $cls . '"' : '') . ' href="' . h($base . $href) . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    };
    return '<nav class="nav" aria-label="Основное меню"><div class="wrap"><a class="brand" href="' . h($base) . '" aria-label="ByteBunch • Lab, на главную">'
        . lab_logo() . '<span class="lab">• Lab</span></a><div class="nav-links">'
        . $a('news/', 'Новости', 'news', 'hide-sm') . $a('sionika/', 'Sionika', 'sionika') . $a('aria/', 'Aria', 'aria')
        . '<a class="tg" href="https://t.me/ByteBunch" target="_blank" rel="noopener">Telegram</a></div></div></nav>';
}

function lab_site_footer(string $base): string
{
    return '<footer class="foot"><div class="wrap"><div class="foot-grid"><div class="foot-mark">' . lab_logo()
        . '<p>ByteBunch • Lab. Разработка ИИ-моделей и софта.</p></div><div><h3>Проекты</h3><ul><li><a href="' . h($base) . 'sionika/">Sionika</a></li><li><a href="' . h($base) . 'aria/">Aria</a></li></ul></div>'
        . '<div><h3>Лаборатория</h3><ul><li><a href="' . h($base) . 'news/">Новости</a></li><li><a href="' . h($base) . '#about">О лаборатории</a></li></ul></div>'
        . '<div><h3>Связь</h3><ul><li><a href="https://t.me/ByteBunch" target="_blank" rel="noopener">Telegram</a></li><li>GitHub<span class="soon">скоро</span></li></ul></div></div>'
        . '<div class="foot-legal"><span>© ' . date('Y') . ' ByteBunch • Lab</span><span></span></div></div></footer>';
}

function lab_site_head(string $base, string $title, string $desc, string $extraCss = ''): string
{
    return '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
        . '<title>' . h($title) . '</title><meta name="description" content="' . h($desc) . '">'
        . '<meta name="theme-color" content="#000000"><meta property="og:type" content="article"><meta property="og:site_name" content="ByteBunch • Lab">'
        . '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '">'
        . '<meta property="og:image" content="' . h(lab_abs_url('assets/og-lab.jpg')) . '"><meta name="twitter:card" content="summary_large_image">'
        . '<link rel="icon" href="' . h($base) . 'assets/favicon.svg" type="image/svg+xml"><link rel="icon" href="' . h($base) . 'assets/favicon-32.png" sizes="32x32" type="image/png">'
        . '<link rel="apple-touch-icon" href="' . h($base) . 'assets/apple-touch-icon.png">'
        . '<link rel="preload" href="' . h($base) . 'assets/fonts/golos-text-cyrillic.woff2" as="font" type="font/woff2" crossorigin>'
        . '<link rel="stylesheet" href="' . h($base) . 'assets/fonts.css"><link rel="stylesheet" href="' . h($base) . 'assets/lab.css">'
        . '<link rel="stylesheet" href="' . h($base) . 'assets/news.css?v=1">' . $extraCss . '</head><body>';
}
