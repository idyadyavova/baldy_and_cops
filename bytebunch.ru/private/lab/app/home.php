<?php
// Главная: новости и открытые HTML-проекты из базы панели.
// Если база недоступна, lab_home_data() вернёт null, и главная покажет свой запасной HTML.

require_once __DIR__ . '/content.php';

function lab_home_data(): ?array
{
    try {
        if (!lab_db_or_null()) return null;
        $featured = lab_post_featured();
        $posts = [];
        foreach (lab_posts_published(6) as $p) {
            if ($featured && (int)$p['id'] === (int)$featured['id']) continue;
            if (count($posts) < 5) $posts[] = $p;
        }
        $total = (int)q('SELECT COUNT(*) FROM ' . T('posts') . " WHERE status = 'published'")->fetchColumn();
        return ['base' => lab_base(), 'featured' => $featured, 'posts' => $posts, 'total' => $total, 'exps' => lab_projects_listed()];
    } catch (Throwable $e) {
        return null;
    }
}

/** Первая фраза описания — для строки «последнее» внизу первого экрана. */
function lab_post_teaser(array $p): string
{
    $t = md_title_plain($p['title']);
    $s = trim((string)$p['summary']);
    if ($s === '') return $t;
    $cut = preg_split('/(?<=[.!?;])\s|,\s(?=а |но |и )/u', $s, 2)[0];
    $cut = rtrim($cut, '.;');
    if (function_exists('mb_strlen') && mb_strlen($cut, 'UTF-8') > 90) $cut = rtrim(mb_substr($cut, 0, 88, 'UTF-8')) . '…';
    return $t . ' — ' . $cut;
}

function lab_home_latest(array $home): string
{
    $p = $home['featured'] ?: ($home['posts'][0] ?? null);
    if (!$p) return '';
    $href = lab_post_href($p, $home['base']);
    return '<div class="latest"><div class="wrap"><a' . ($href ? ' href="' . h($href) . '"' : '') . '>' . lab_post_time_html($p)
        . '<span>' . h(lab_post_teaser($p)) . '</span><span class="ar">→</span></a></div></div>';
}

function lab_home_news(array $home): string
{
    $base = $home['base'];
    $out = '';
    if ($f = $home['featured']) {
        $href = lab_post_href($f, $base);
        $cover = lab_cover_src($f['cover'], $base);
        $figure = '';
        if ($f['cover'] === 'aria-dial') {
            $figure = '<figure class="dial" style="margin:0"><svg id="dial" viewBox="0 0 520 150" role="img" aria-label="Шкала центов: Aria показывает 0, GuitarTuna +16 на точно настроенной струне E2"></svg>'
                . ($f['cover_caption'] !== '' ? '<figcaption>' . h($f['cover_caption']) . '</figcaption>' : '') . '</figure>';
        } elseif ($cover) {
            $figure = '<figure class="dial" style="margin:0;padding:0"><img src="' . h($cover) . '" alt="" loading="lazy">'
                . ($f['cover_caption'] !== '' ? '<figcaption style="padding:0 16px 14px">' . h($f['cover_caption']) . '</figcaption>' : '') . '</figure>';
        }
        $more = $f['tag'] === 'Релиз' ? 'Что нового' : 'Подробнее';
        $out .= '<article class="feature rv"' . ($figure === '' ? ' style="grid-template-columns:minmax(0,1fr)"' : '') . '><div>'
            . '<div class="kind">' . lab_post_time_html($f) . ($f['tag'] !== '' ? '<span>' . h($f['tag']) . '</span>' : '') . '</div>'
            . '<h3>' . md_title($f['title']) . '</h3>'
            . ($f['summary'] !== '' ? '<p>' . h($f['summary']) . '</p>' : '')
            . ($href ? '<a class="more" href="' . h($href) . '">' . $more . ' <span class="ar">→</span></a>' : '')
            . '</div>' . $figure . '</article>';
    }
    $out .= '<ol class="news">';
    foreach ($home['posts'] as $p) {
        $href = lab_post_href($p, $base);
        $out .= '<li class="rv' . ($href ? '' : ' soon-row') . '"><a' . ($href ? ' href="' . h($href) . '"' : '') . '>' . lab_post_time_html($p)
            . '<span class="tag">' . h($p['tag']) . '</span><span class="t"><b>' . h(md_title_plain($p['title'])) . '</b>'
            . ($p['summary'] !== '' ? '<span>' . h($p['summary']) . '</span>' : '') . '</span><span class="ar">' . ($href ? '→' : '') . '</span></a></li>';
    }
    $out .= '</ol>';
    $shown = count($home['posts']) + ($home['featured'] ? 1 : 0);
    if ($home['total'] > $shown) {
        $out .= '<p class="rv" style="margin-top:28px"><a class="more" href="' . h($base) . 'news/">Все новости <span class="ar">→</span></a></p>';
    }
    return $out;
}

/** Открытые HTML-проекты, отмеченные «показывать на главной». */
function lab_home_experiments(array $home): string
{
    if (!$home['exps']) return '';
    $base = $home['base'];
    $out = '<div class="rv" style="margin-top:48px"><h3 style="font:500 13px/1.4 var(--f-mono);color:var(--ink-2);margin-bottom:14px">Эксперименты · HTML-проекты</h3>'
        . '<ul class="exp" style="list-style:none;margin:0;padding:0;border-top:1px solid var(--line)">';
    foreach ($home['exps'] as $p) {
        $out .= '<li><a href="' . h($base . lab_project_path($p)) . '" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 24px;padding:18px 0;border-bottom:1px solid var(--line);text-decoration:none">'
            . '<b style="font-weight:500;font-size:17px">' . h($p['title']) . '</b><span class="ar" style="color:var(--muted);font:400 13px var(--f-mono)">открыть →</span>'
            . ($p['description'] !== '' ? '<span style="color:var(--ink-2);font-size:15px">' . h($p['description']) . '</span>' : '') . '</a></li>';
    }
    return $out . '</ul></div>';
}
