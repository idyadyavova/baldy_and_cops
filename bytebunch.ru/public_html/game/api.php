<?php
// Связь «компьютер с игрой ⇄ телефон ведущего».
// Компьютер раз в долю секунды присылает состояние игры и забирает команды,
// телефон присылает команды и забирает состояние. Всё хранится в private/rooms.
require __DIR__ . '/../../private/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function out($data, $code = 200) {
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    echo json_encode($data, $flags);
    exit;
}
function rd($file, $def) {
    if (!is_file($file)) return $def;
    $j = json_decode((string)file_get_contents($file), true);
    return is_array($j) ? $j : $def;
}
function wr($file, $data) {
    $flags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, $flags));
    rename($tmp, $file);
}

if (ACCESS_KEY === 'ЗАМЕНИТЕ_НА_СВОЙ_ДЛИННЫЙ_КЛЮЧ' || strlen(ACCESS_KEY) < 12) out(['error' => 'not found'], 404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'not found'], 404);

$raw = file_get_contents('php://input', false, null, 0, 3000000);
$in = json_decode((string)$raw, true);
if (!is_array($in)) $in = [];

$key = $in['k'] ?? '';
if (!is_string($key) || !hash_equals(ACCESS_KEY, $key)) out(['error' => 'not found'], 404);

$room = $_GET['room'] ?? '';
if (!is_string($room) || !preg_match('/^[A-Za-z0-9]{16,40}$/', $room)) out(['error' => 'room'], 400);
$op = $_GET['op'] ?? '';

$dir = __DIR__ . '/../../private/rooms';
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) out(['error' => 'storage'], 500);

// изредка убираем старые комнаты (старше двух суток)
if (mt_rand(1, 80) === 1) {
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 2 * 86400) @unlink($f);
    }
}

$base = $dir . '/' . $room;
$lock = fopen($base . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) out(['error' => 'lock'], 500);

$now = microtime(true);
$meta = rd($base . '.meta.json', ['v' => 0, 'cid' => 0, 'engine' => 0, 'clients' => []]);
if (!isset($meta['clients']) || !is_array($meta['clients'])) $meta['clients'] = [];

// компьютер с игрой: отдаёт состояние, забирает команды
if ($op === 'engine') {
    $meta['engine'] = $now;
    if (isset($in['state']) && is_array($in['state'])) {
        $meta['v'] = (int)$meta['v'] + 1;
        wr($base . '.state.json', ['v' => $meta['v'], 'state' => $in['state']]);
    }
    $since = isset($in['since']) ? (int)$in['since'] : -1;
    $cmds = [];
    if ($since >= 0) {
        foreach (rd($base . '.cmds.json', []) as $c) {
            if (isset($c['id']) && $c['id'] > $since) { $c['age'] = round($now - $c['t'], 2); $cmds[] = $c; }
        }
    }
    $alive = 0;
    foreach ($meta['clients'] as $cl => $t) {
        if ($now - $t < 6) $alive++; else unset($meta['clients'][$cl]);
    }
    wr($base . '.meta.json', $meta);
    out(['cmds' => $cmds, 'last' => (int)$meta['cid'], 'remotes' => $alive]);
}

// телефон: присылает команды, забирает состояние
if ($op === 'remote') {
    $client = $in['client'] ?? '';
    if (!is_string($client) || !preg_match('/^[A-Za-z0-9]{6,24}$/', $client)) out(['error' => 'client'], 400);
    $meta['clients'][$client] = $now;
    $add = (isset($in['cmds']) && is_array($in['cmds'])) ? array_slice($in['cmds'], 0, 20) : [];
    if ($add) {
        $cmds = rd($base . '.cmds.json', []);
        foreach ($add as $c) {
            if (!is_array($c) || !isset($c['c']) || !is_string($c['c']) || !preg_match('/^[A-Za-z]{1,24}$/', $c['c'])) continue;
            $args = (isset($c['a']) && is_array($c['a'])) ? array_slice(array_values($c['a']), 0, 4) : [];
            foreach ($args as $i => $a) {
                if (is_string($a)) $args[$i] = function_exists('mb_substr') ? mb_substr($a, 0, 200, 'UTF-8') : substr($a, 0, 400);
                elseif (!is_int($a) && !is_float($a) && !is_bool($a) && $a !== null) $args[$i] = null;
            }
            $meta['cid'] = (int)$meta['cid'] + 1;
            $u = (isset($c['u']) && is_string($c['u']) && preg_match('/^[A-Za-z0-9]{4,40}$/', $c['u'])) ? $c['u'] : '';
            $cmds[] = ['id' => $meta['cid'], 'c' => $c['c'], 'a' => $args, 'u' => $u, 't' => $now];
        }
        wr($base . '.cmds.json', array_slice($cmds, -100));
    }
    wr($base . '.meta.json', $meta);
    $since = isset($in['since']) ? (int)$in['since'] : 0;
    $resp = ['v' => (int)$meta['v'], 'engine' => ($now - (float)$meta['engine']) < 6];
    if ($meta['v'] > $since) {
        $st = rd($base . '.state.json', null);
        if ($st) { $resp['v'] = (int)$st['v']; $resp['state'] = $st['state']; }
    }
    out($resp);
}

out(['error' => 'op'], 400);
