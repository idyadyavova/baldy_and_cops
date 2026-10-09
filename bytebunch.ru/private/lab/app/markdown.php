<?php
// Простая и безопасная разметка для постов. Любой HTML в тексте экранируется, ссылки — только http(s), почта
// и адреса внутри сайта. Поддерживается:
//   # Заголовок, ## Подзаголовок      **жирный**, *курсив*, `код`, [текст](адрес)
//   - список, 1. список, > цитата      ![подпись](картинка) на отдельной строке
//   ``` блок кода ```, --- линия        {{project:адрес-проекта}} — встроить открытый HTML-проект

function md_url(string $u, bool $image = false): ?string
{
    $u = trim($u);
    if ($u === '' || preg_match('/[\x00-\x20"\'<>\\\\]/', $u)) return null;
    if (preg_match('~^https?://~i', $u)) return $u;
    if (!$image && preg_match('~^mailto:[^@\s]+@[^@\s]+$~i', $u)) return $u;
    if (!$image && preg_match('~^tg://~i', $u)) return $u;
    if ($u[0] === '#' || $u[0] === '/' || $u[0] === '?') return strpos($u, '//') === 0 ? null : $u;
    // относительный адрес без схемы: двоеточия до первого «/» быть не должно (защита от javascript:)
    $head = explode('/', $u, 2)[0];
    return strpos($head, ':') === false ? $u : null;
}

/** Адрес внутри сайта считаем от корня лаборатории, чтобы ссылки работали и в новостях, и на главной. */
function md_href(string $u, string $base): string
{
    if (preg_match('~^(https?:|mailto:|tg:|#|/|\?)~i', $u)) return $u;
    return $base . $u;
}

function md_inline(string $s, string $base): string
{
    $re = '/`([^`\n]+)`'                                    // 1 код
        . '|\[([^\]\n]+)\]\(([^)\s]+)\)'                    // 2,3 ссылка
        . '|\*\*(?=\S)(.+?)(?<=\S)\*\*'                    // 4 жирный
        . '|(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])'      // 5 курсив
        . '|(?<![\w\/="])(https?:\/\/[^\s<>()]+[^\s<>().,;:!?»"\'])/u'; // 6 голый адрес
    $out = '';
    $pos = 0;
    while (preg_match($re, $s, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $start = $m[0][1];
        $out .= h(substr($s, $pos, $start - $pos));
        if (isset($m[1]) && $m[1][1] >= 0 && $m[1][0] !== '') {
            $out .= '<code>' . h($m[1][0]) . '</code>';
        } elseif (isset($m[2]) && $m[2][1] >= 0 && $m[2][0] !== '') {
            $url = md_url($m[3][0]);
            $inner = md_inline($m[2][0], $base);
            if ($url === null) {
                $out .= $inner;
            } else {
                $ext = (bool)preg_match('~^https?://~i', $url);
                $out .= '<a href="' . h(md_href($url, $base)) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . $inner . '</a>';
            }
        } elseif (isset($m[4]) && $m[4][1] >= 0 && $m[4][0] !== '') {
            $out .= '<strong>' . md_inline($m[4][0], $base) . '</strong>';
        } elseif (isset($m[5]) && $m[5][1] >= 0 && $m[5][0] !== '') {
            $out .= '<em>' . md_inline($m[5][0], $base) . '</em>';
        } else {
            $url = $m[6][0];
            $out .= '<a href="' . h($url) . '" target="_blank" rel="noopener">' . h($url) . '</a>';
        }
        $pos = $start + strlen($m[0][0]);
    }
    return $out . h(substr($s, $pos));
}

/** Заголовок поста: только *курсив* — он подсвечивается цветом, как «Aria» в «Aria 0.6.2». */
function md_title(string $s): string
{
    $out = '';
    foreach (preg_split('/(\*[^*\n]+\*)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        $out .= preg_match('/^\*([^*]+)\*$/u', $part, $m) ? '<em>' . h($m[1]) . '</em>' : h($part);
    }
    return $out;
}

function md_title_plain(string $s): string
{
    return preg_replace('/\*([^*\n]+)\*/u', '$1', $s);
}

function md_anchor(string $text, array &$used): string
{
    $a = lab_slugify(md_title_plain($text)) ?: 'r';
    $x = $a;
    for ($i = 2; isset($used[$x]); $i++) $x = $a . '-' . $i;
    $used[$x] = true;
    return $x;
}

/**
 * @param callable|null $embed функция (адрес проекта) → HTML встраивания или '' (проекта нет / он закрыт)
 */
function md_render(string $text, string $base, ?callable $embed = null): string
{
    $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    $html = '';
    $para = [];
    $list = null;     // ['ul'|'ol', [пункты]]
    $quote = [];
    $anchors = [];

    $flushPara = function () use (&$para, &$html, $base) {
        if ($para) $html .= '<p>' . implode('<br>', array_map(function ($l) use ($base) { return md_inline($l, $base); }, $para)) . "</p>\n";
        $para = [];
    };
    $flushList = function () use (&$list, &$html, $base) {
        if ($list) {
            $html .= '<' . $list[0] . ">\n";
            foreach ($list[1] as $item) $html .= '<li>' . md_inline($item, $base) . "</li>\n";
            $html .= '</' . $list[0] . ">\n";
        }
        $list = null;
    };
    $flushQuote = function () use (&$quote, &$html, $base) {
        if ($quote) $html .= '<blockquote>' . md_render(implode("\n", $quote), $base) . "</blockquote>\n";
        $quote = [];
    };
    $flushAll = function () use ($flushPara, $flushList, $flushQuote) {
        $flushPara();
        $flushList();
        $flushQuote();
    };

    for ($i = 0, $n = count($lines); $i < $n; $i++) {
        $line = rtrim($lines[$i]);

        if (preg_match('/^```\s*([\w+-]*)\s*$/', $line, $m)) {
            $flushAll();
            $code = [];
            for ($i++; $i < $n && !preg_match('/^```\s*$/', rtrim($lines[$i])); $i++) $code[] = $lines[$i];
            $html .= '<pre><code' . ($m[1] !== '' ? ' data-lang="' . h($m[1]) . '"' : '') . '>' . h(implode("\n", $code)) . "</code></pre>\n";
            continue;
        }
        if ($quote && !preg_match('/^>/', $line)) $flushQuote();
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara();
            $flushList();
            $quote[] = $m[1];
            continue;
        }
        if (trim($line) === '') {
            $flushAll();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+?)\s*#*$/', $line, $m)) {
            $flushAll();
            $lvl = max(2, min(4, strlen($m[1])));   // # и ## → h2 (h1 на странице — заголовок поста), ### → h3
            $html .= '<h' . $lvl . ' id="' . md_anchor($m[2], $anchors) . '">' . md_inline($m[2], $base) . '</h' . $lvl . ">\n";
            continue;
        }
        if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', trim($line))) {
            $flushAll();
            $html .= "<hr>\n";
            continue;
        }
        if (preg_match('/^\{\{\s*project\s*:\s*([a-z0-9-]+)\s*\}\}$/', trim($line), $m)) {
            $flushAll();
            if ($embed) $html .= $embed($m[1]);
            continue;
        }
        if (preg_match('/^!\[([^\]]*)\]\(([^)\s]+)\)$/', trim($line), $m)) {
            $flushAll();
            $src = md_url($m[2], true);
            if ($src !== null) {
                $html .= '<figure><img src="' . h(md_href($src, $base)) . '" alt="' . h($m[1]) . '" loading="lazy">'
                    . ($m[1] !== '' ? '<figcaption>' . h($m[1]) . '</figcaption>' : '') . "</figure>\n";
            }
            continue;
        }
        if (preg_match('/^\s{0,3}[-*+]\s+(.*)$/', $line, $m) || preg_match('/^\s{0,3}\d{1,3}[.)]\s+(.*)$/', $line, $m2)) {
            $type = isset($m[1]) ? 'ul' : 'ol';
            $item = $type === 'ul' ? $m[1] : $m2[1];
            $flushPara();
            if ($list && $list[0] !== $type) $flushList();
            if (!$list) $list = [$type, []];
            $list[1][] = $item;
            continue;
        }
        if ($list && preg_match('/^\s{2,}(\S.*)$/', $line, $m)) {   // продолжение пункта списка
            $list[1][count($list[1]) - 1] .= ' ' . $m[1];
            continue;
        }
        $flushList();
        $para[] = $line;
    }
    $flushAll();
    return $html;
}
