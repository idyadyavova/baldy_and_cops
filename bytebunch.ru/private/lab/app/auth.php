<?php
// Вход в панель: пароли, сессии, защита форм, ограничение попыток, коды из приложения (2FA).
//
// Как устроено:
// • пароль хранится только хешем (Argon2id или bcrypt), сам пароль нигде не сохраняется;
// • сессия — случайный токен в cookie с флагами HttpOnly, Secure, SameSite=Strict; в базе лежит лишь его SHA-256,
//   поэтому даже утёкшая база не даёт войти чужими сессиями;
// • каждая форма несёт CSRF-токен сессии, а запросы с чужого сайта отсекаются по заголовку Origin;
// • после 8 неудачных входов с одного IP за 15 минут вход с него закрывается на 15 минут.

const LAB_SESSION_IDLE = 86400;          // сутки без действий — и сессия заканчивается
const LAB_SESSION_MAX = 14 * 86400;      // две недели — предел для любой сессии
const LAB_THROTTLE_WINDOW = 900;         // 15 минут
const LAB_THROTTLE_IP = 8;               // неудач с одного IP за окно
const LAB_THROTTLE_LOGIN = 25;           // неудач для одного логина за окно (с любых IP)

function lab_hash_password(string $p): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($p, PASSWORD_ARGON2ID);
    }
    return password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
}

/** Проверка нового пароля. Возвращает текст ошибки или ''. */
function lab_password_problem(string $p, string $login = ''): string
{
    $len = function_exists('mb_strlen') ? mb_strlen($p, 'UTF-8') : strlen($p);
    if ($len < 12) return 'Пароль должен быть не короче 12 символов.';
    if ($len > 200) return 'Пароль слишком длинный.';
    if ($login !== '' && stripos($p, $login) !== false) return 'Пароль не должен содержать логин.';
    if (count(array_unique(str_split($p))) < 6) return 'В пароле слишком мало разных символов.';
    $weak = ['password', 'qwerty', '123456', 'bytebunch', 'sionika', 'admin', 'йцукен'];
    foreach ($weak as $w) if (stripos($p, $w) !== false && $len < 20) return 'Пароль слишком предсказуемый: в нём «' . $w . '».';
    return '';
}

// ─── cookie ──────────────────────────────────────────────────────────────────

function lab_cookie_name(string $base): string
{
    // приставка __Host- запрещает браузеру принимать такую cookie без HTTPS, с доменом или чужим путём
    return lab_is_https() ? '__Host-' . $base : $base;
}

function lab_set_cookie(string $base, string $value, int $expires): void
{
    $opts = ['expires' => $expires, 'path' => '/', 'secure' => lab_is_https(), 'httponly' => true, 'samesite' => 'Strict'];
    setcookie(lab_cookie_name($base), $value, $opts);
}

// ─── попытки ─────────────────────────────────────────────────────────────────

function lab_attempt(string $kind, string $subject, bool $ok): void
{
    q('INSERT INTO ' . T('attempts') . ' (kind, ip, subject, ok, created_at) VALUES (?, ?, ?, ?, ?)',
        [$kind, lab_ip(), substr($subject, 0, 64), $ok ? 1 : 0, lab_now()]);
    if (mt_rand(1, 50) === 1) {
        q('DELETE FROM ' . T('attempts') . ' WHERE created_at < ?', [lab_now(-30 * 86400)]);
    }
}

/** Сколько секунд ещё ждать, если попыток было слишком много; 0 — можно пробовать. */
function lab_throttled(string $kind, string $subject = ''): int
{
    $since = lab_now(-LAB_THROTTLE_WINDOW);
    $st = q('SELECT COUNT(*) n, MAX(created_at) last FROM ' . T('attempts') . ' WHERE kind = ? AND ip = ? AND ok = 0 AND created_at >= ?',
        [$kind, lab_ip(), $since])->fetch();
    $last = null;
    if ((int)$st['n'] >= LAB_THROTTLE_IP) $last = $st['last'];
    if ($subject !== '') {
        $st2 = q('SELECT COUNT(*) n, MAX(created_at) last FROM ' . T('attempts') . ' WHERE kind = ? AND subject = ? AND ok = 0 AND created_at >= ?',
            [$kind, substr($subject, 0, 64), $since])->fetch();
        if ((int)$st2['n'] >= LAB_THROTTLE_LOGIN) $last = max((string)$last, (string)$st2['last']);
    }
    if (!$last) return 0;
    return max(1, strtotime($last . ' UTC') + LAB_THROTTLE_WINDOW - time());
}

// ─── сессии ──────────────────────────────────────────────────────────────────

function lab_session_start(int $userId, bool $mfaPending): void
{
    $token = lab_token();
    q('INSERT INTO ' . T('sessions') . ' (id, user_id, csrf, mfa_pending, created_at, last_seen, ip, ua) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [hash('sha256', $token), $userId, lab_token(), $mfaPending ? 1 : 0, lab_now(), lab_now(), lab_ip(), lab_ua()]);
    lab_set_cookie('bblab', $token, 0);   // живёт до закрытия браузера; срок на сервере — до двух недель
    $_COOKIE[lab_cookie_name('bblab')] = $token;
    if (mt_rand(1, 20) === 1) {
        q('DELETE FROM ' . T('sessions') . ' WHERE last_seen < ? OR created_at < ?', [lab_now(-LAB_SESSION_IDLE), lab_now(-LAB_SESSION_MAX)]);
    }
}

/** Текущая сессия (вместе с пользователем) или null. */
function lab_session(bool $allowPending = false): ?array
{
    static $cache = [];
    $key = $allowPending ? 'p' : 'f';
    if (array_key_exists($key, $cache)) return $cache[$key];

    $token = $_COOKIE[lab_cookie_name('bblab')] ?? '';
    if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return $cache[$key] = null;
    try {
        $s = q('SELECT s.*, u.login, u.totp_enabled FROM ' . T('sessions') . ' s JOIN ' . T('users') . ' u ON u.id = s.user_id WHERE s.id = ?',
            [hash('sha256', $token)])->fetch();
    } catch (Throwable $e) {
        return $cache[$key] = null;
    }
    if (!$s) return $cache[$key] = null;
    $seen = strtotime($s['last_seen'] . ' UTC');
    $born = strtotime($s['created_at'] . ' UTC');
    if ($seen < time() - LAB_SESSION_IDLE || $born < time() - LAB_SESSION_MAX) {
        q('DELETE FROM ' . T('sessions') . ' WHERE id = ?', [$s['id']]);
        return $cache[$key] = null;
    }
    if ((int)$s['mfa_pending'] === 1) {
        // ожидание кода живёт 10 минут
        if ($born < time() - 600) {
            q('DELETE FROM ' . T('sessions') . ' WHERE id = ?', [$s['id']]);
            return $cache[$key] = null;
        }
        if (!$allowPending) return $cache[$key] = null;
    }
    if ($seen < time() - 120) {
        q('UPDATE ' . T('sessions') . ' SET last_seen = ?, ip = ? WHERE id = ?', [lab_now(), lab_ip(), $s['id']]);
    }
    return $cache[$key] = $s;
}

function lab_session_end(): void
{
    $s = lab_session(true);
    if ($s) q('DELETE FROM ' . T('sessions') . ' WHERE id = ?', [$s['id']]);
    lab_set_cookie('bblab', '', time() - 3600);
}

// ─── CSRF ────────────────────────────────────────────────────────────────────

/** Запрос пришёл с нашего же сайта? Браузеры присылают Origin со всеми POST. */
function lab_same_origin(): bool
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || $origin === 'null') {
        $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') return $origin === '';   // без Origin и Referer — старые браузеры; полагаемся на токен
        $origin = $ref;
    }
    $host = parse_url($origin, PHP_URL_HOST);
    $port = parse_url($origin, PHP_URL_PORT);
    return is_string($host) && strcasecmp($host . ($port ? ':' . $port : ''), lab_host()) === 0;
}

/** Токен для форм входа, когда сессии ещё нет: cookie + такое же значение в форме. */
function lab_pre_token(): string
{
    $name = lab_cookie_name('bblab_pre');
    $t = $_COOKIE[$name] ?? '';
    if (!is_string($t) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $t)) {
        $t = lab_token();
        lab_set_cookie('bblab_pre', $t, 0);
        $_COOKIE[$name] = $t;
    }
    return $t;
}

function lab_check_pre_token(): bool
{
    $c = $_COOKIE[lab_cookie_name('bblab_pre')] ?? '';
    $f = $_POST['_csrf'] ?? '';
    return is_string($c) && is_string($f) && $c !== '' && hash_equals($c, $f) && lab_same_origin();
}

function lab_csrf_field(array $session): string
{
    return '<input type="hidden" name="_csrf" value="' . h($session['csrf']) . '">';
}

function lab_check_csrf(array $session): bool
{
    $f = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($f) && hash_equals($session['csrf'], $f) && lab_same_origin();
}

// ─── TOTP (Google Authenticator, Aegis, 2FAS…) ───────────────────────────────

function lab_base32_encode(string $bin): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $abc[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function lab_base32_decode(string $s): string
{
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($abc, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function lab_totp_at(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('N*', 0, $step), lab_base32_decode($secret), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Шаг времени, на котором код совпал (±30 секунд на расхождение часов), или 0. */
function lab_totp_match(string $secret, string $code, int $notBefore = 0): int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return 0;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step <= $notBefore) continue;   // один и тот же код дважды не принимаем
        if (hash_equals(lab_totp_at($secret, $step), $code)) return $step;
    }
    return 0;
}

function lab_totp_uri(string $secret, string $login): string
{
    $label = rawurlencode('ByteBunch Lab:' . $login);
    return 'otpauth://totp/' . $label . '?secret=' . $secret . '&issuer=' . rawurlencode('ByteBunch Lab') . '&digits=6&period=30';
}
