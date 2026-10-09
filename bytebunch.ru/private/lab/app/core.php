<?php
// Ядро панели ByteBunch • Lab: настройки, база, общие функции.
// Каталог private/ лежит вне public_html, поэтому из браузера эти файлы не открыть.
// Страница, которая подключает ядро, заранее задаёт LAB_DEPTH — на сколько папок она глубже корня лаборатории.

if (PHP_VERSION_ID < 70400) {
    http_response_code(500);
    exit('Панели нужен PHP 7.4 или новее. Версию PHP меняют в панели хостинга.');
}

define('LAB_PRIVATE', dirname(__DIR__));                              // private/lab
define('LAB_DATA', LAB_PRIVATE . '/data');                            // проекты и служебные файлы
define('LAB_WEB', dirname(LAB_PRIVATE, 2) . '/public_html/lab');      // корень сайта лаборатории
define('LAB_SCHEMA', 1);

// Ошибка на сервере: подробности — в журнал ошибок хостинга, посетителю — короткое сообщение без деталей.
set_exception_handler(function (Throwable $e) {
    error_log('[lab] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo 'Ошибка на сервере. Подробности — в журнале ошибок хостинга.';
});

function lab_config(): ?array
{
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $file = LAB_PRIVATE . '/config.php';
    $cfg = is_file($file) ? require $file : null;
    if (!is_array($cfg) || !isset($cfg['db']) || !is_array($cfg['db'])) return $cfg = null;
    $cfg += ['require_https' => true, 'timezone' => 'UTC', 'max_upload_mb' => 100];
    $cfg['db'] += ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'lab_'];
    if (!preg_match('/^[a-z0-9_]{0,20}$/', (string)$cfg['db']['prefix'])) $cfg['db']['prefix'] = 'lab_';
    @date_default_timezone_set((string)$cfg['timezone']);
    return $cfg;
}

/** Подключение к MySQL. Бросает исключение, если базы нет или пароль неверный. */
function lab_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = lab_config();
    if (!$c) throw new RuntimeException('Нет private/lab/config.php');
    $db = $c['db'];
    $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';
    if (!empty($db['port'])) $dsn .= ';port=' . (int)$db['port'];
    $pdo = new PDO($dsn, (string)$db['user'], (string)$db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** База для публичных страниц: если что-то не так, страница покажет запасной вариант, а не ошибку. */
function lab_db_or_null(): ?PDO
{
    try {
        if (!lab_config()) return null;
        $pdo = lab_db();
        return lab_meta('schema') ? $pdo : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Имя таблицы с приставкой из настроек. */
function T(string $name): string
{
    $c = lab_config();
    return '`' . ($c ? $c['db']['prefix'] : 'lab_') . $name . '`';
}

function q(string $sql, array $args = []): PDOStatement
{
    $st = lab_db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function lab_meta(string $k): ?string
{
    try {
        $v = q('SELECT v FROM ' . T('meta') . ' WHERE k = ?', [$k])->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (PDOException $e) {
        return null;   // таблиц ещё нет
    }
}

function lab_meta_set(string $k, string $v): void
{
    q('INSERT INTO ' . T('meta') . ' (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

function lab_secret(): string
{
    $s = lab_meta('secret');
    if (!$s) throw new RuntimeException('Панель не установлена');
    return $s;
}

// ─── время ───────────────────────────────────────────────────────────────────

/** Время для базы: всегда UTC. */
function lab_now(int $shift = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $shift);
}

/** Время из базы (UTC) в часовом поясе из настроек. */
function lab_local(?string $utc, string $fmt = 'd.m.Y H:i'): string
{
    if (!$utc) return '—';
    $t = strtotime($utc . ' UTC');
    return $t ? date($fmt, $t) : '—';
}

// ─── запрос ──────────────────────────────────────────────────────────────────

function lab_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function lab_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function lab_ua(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return function_exists('mb_substr') ? mb_substr($ua, 0, 250, 'UTF-8') : substr($ua, 0, 250);
}

function lab_host(): string
{
    return preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

/** Путь к корню лаборатории в адресе: «/» на lab.bytebunch.ru или «/lab/» на bytebunch.ru/lab. */
function lab_base(): string
{
    static $b = null;
    if ($b !== null) return $b;
    $d = dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $depth = defined('LAB_DEPTH') ? LAB_DEPTH : 0;
    for ($i = 0; $i < $depth; $i++) $d = dirname($d);
    $d = rtrim(str_replace('\\', '/', $d), '/');
    return $b = $d . '/';
}

function lab_url(string $path = ''): string
{
    return lab_base() . ltrim($path, '/');
}

function lab_abs_url(string $path = ''): string
{
    return (lab_is_https() ? 'https' : 'http') . '://' . lab_host() . lab_url($path);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post_str(string $k, int $max = 100000): string
{
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) return '';
    $v = str_replace("\r\n", "\n", $v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

function get_str(string $k): string
{
    $v = $_GET[$k] ?? '';
    return is_string($v) ? $v : '';
}

// ─── вывод ───────────────────────────────────────────────────────────────────

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lab_redirect(string $to, int $code = 303): void
{
    header('Location: ' . $to, true, $code);
    exit;
}

function lab_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lab_logo(): string
{
    static $svg = null;
    return $svg ?? ($svg = (string)file_get_contents(__DIR__ . '/logo.svg'));
}

function lab_bytes(int $n): string
{
    if ($n < 1024) return $n . ' Б';
    if ($n < 1048576) return round($n / 1024) . ' КБ';
    return number_format($n / 1048576, $n < 10485760 ? 1 : 0, ',', ' ') . ' МБ';
}

// ─── случайные строки ────────────────────────────────────────────────────────

/** Токен для cookie: 32 случайных байта в base64url (43 символа). */
function lab_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Ключ из латиницы и цифр — для ссылок, его удобно копировать. 24 символа ≈ 143 бита. */
function lab_key(int $len = 24): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    while (strlen($out) < $len) {
        foreach (str_split(random_bytes($len)) as $ch) {
            $b = ord($ch);
            if ($b < 248) $out .= $abc[$b % 62];   // 248 = 4·62: без перекоса в распределении
            if (strlen($out) === $len) break;
        }
    }
    return $out;
}

// ─── адреса и имена ──────────────────────────────────────────────────────────

/** Адрес из латиницы для поста или проекта: «Aria 0.6.2!» → «aria-0-6-2». */
function lab_slugify(string $s): string
{
    static $tr = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya','і'=>'i','ї'=>'yi','є'=>'ye','ґ'=>'g'];
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, $tr);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim((string)$s, '-'), 0, 80);
}

function lab_valid_slug(string $s): bool
{
    return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/', $s);
}

// ─── журнал ──────────────────────────────────────────────────────────────────

function lab_audit(?int $userId, string $action, string $details = ''): void
{
    try {
        q('INSERT INTO ' . T('audit') . ' (user_id, action, details, ip, created_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $action, function_exists('mb_substr') ? mb_substr($details, 0, 480, 'UTF-8') : substr($details, 0, 480), lab_ip(), lab_now()]);
    } catch (Throwable $e) {
        // журнал не должен ломать действие
    }
}

// ─── файлы ───────────────────────────────────────────────────────────────────

function lab_data_dir(string $sub = ''): string
{
    $dir = LAB_DATA . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        if (!is_file(LAB_DATA . '/.htaccess')) {
            // на случай, если private/ когда-нибудь окажется внутри public_html
            @file_put_contents(LAB_DATA . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }
    return $dir;
}

function lab_write_atomic(string $file, string $data): bool
{
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $data, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function lab_rmtree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        if (is_file($dir) || is_link($dir)) @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        lab_rmtree($dir . '/' . $f);
    }
    @rmdir($dir);
}

/** Лимит загрузки: меньшее из настроек панели и настроек PHP. */
function lab_upload_limit(): int
{
    $toBytes = function ($v): int {
        $v = trim((string)$v);
        if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
        $n = (float)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return (int)$n;
    };
    $c = lab_config();
    $mine = (int)(($c['max_upload_mb'] ?? 100) * 1048576);
    return (int)min($mine, $toBytes(ini_get('upload_max_filesize')), $toBytes(ini_get('post_max_size')));
}

/** POST больше post_max_size: PHP молча выбрасывает все поля и файлы. */
function lab_post_too_big(): bool
{
    return is_post() && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function lab_upload_error(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'Файл больше, чем разрешает хостинг (' . lab_bytes(lab_upload_limit()) . ').';
        case UPLOAD_ERR_PARTIAL: return 'Файл загрузился не полностью. Попробуйте ещё раз.';
        case UPLOAD_ERR_NO_FILE: return 'Файл не выбран.';
        default: return 'Хостинг не принял файл (код ' . $code . ').';
    }
}
