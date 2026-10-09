<?php
// Sionika: выпуск новой версии для проверки обновлений и статистика запусков.
// Проверку обновлений обслуживает public_html/sionika/index.php (sionika.bytebunch.ru).
// Панель пишет выпуск в private/lab/data/sionika-release.json, а index.php берёт его оттуда.

require_once __DIR__ . '/core.php';

define('LAB_SIONIKA_DIR', dirname(LAB_WEB) . '/sionika');

function lab_sionika_release_file(): string
{
    return LAB_DATA . '/sionika-release.json';
}

/** Значения, вписанные прямо в sionika/index.php, — пока выпуск ни разу не публиковали из панели. */
function lab_sionika_builtin(): ?array
{
    $src = @file_get_contents(LAB_SIONIKA_DIR . '/index.php');
    if (!$src) return null;
    $r = [];
    if (preg_match("/'versionCode'\\s*=>\\s*(\\d+)/", $src, $m)) $r['versionCode'] = (int)$m[1];
    if (preg_match("/'versionName'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) $r['versionName'] = stripslashes($m[1]);
    if (preg_match('/\'changes\'\s*=>\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $m)) $r['changes'] = stripcslashes($m[1]);
    elseif (preg_match("/'changes'\\s*=>\\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) $r['changes'] = stripslashes($m[1]);
    if (preg_match("/'url'\\s*=>\\s*'([^']*)'/", $src, $m)) $r['url'] = $m[1];
    return isset($r['versionCode'], $r['versionName']) ? $r + ['changes' => '', 'url' => 'https://t.me/ByteBunch'] : null;
}

/** Текущий выпуск: из панели, иначе из index.php. */
function lab_sionika_current(): array
{
    $f = lab_sionika_release_file();
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && isset($j['versionCode'])) return $j + ['source' => 'panel'];
    }
    $b = lab_sionika_builtin();
    return $b ? $b + ['source' => 'file'] : ['versionCode' => 0, 'versionName' => '', 'changes' => '', 'url' => 'https://t.me/ByteBunch', 'source' => 'none'];
}

/** Приложение открывает только t.me/ByteBunch и страницы на *.bytebunch.ru — проверяем то же самое. */
function lab_sionika_url_ok(string $u): bool
{
    return (bool)preg_match('~^https://t\.me/ByteBunch(/[^\s]*)?$~', $u)
        || (bool)preg_match('~^https?://([a-z0-9-]+\.)*bytebunch\.ru(/[^\s]*)?$~i', $u);
}

function lab_sionika_publish(array $r): bool
{
    $data = [
        'versionCode' => (int)$r['versionCode'],
        'versionName' => (string)$r['versionName'],
        'changes' => (string)$r['changes'],
        'url' => (string)$r['url'],
        'updated_at' => gmdate('c'),
    ];
    lab_data_dir();
    return lab_write_atomic(lab_sionika_release_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/** Статистика проверок обновлений: день → страна → число запросов. */
function lab_sionika_stats(): array
{
    $raw = @file_get_contents(LAB_SIONIKA_DIR . '/stats-data.php');
    if (!$raw) return [];
    $nl = strpos($raw, "\n");
    $j = json_decode($nl === false ? '' : substr($raw, $nl + 1), true);
    if (!is_array($j)) return [];
    krsort($j);
    return $j;
}

function lab_country_name(string $code): string
{
    if ($code === '??') return 'не определена';
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $code, 'ru');
        if ($n !== '' && $n !== $code) return $n;
    }
    return $code;
}
