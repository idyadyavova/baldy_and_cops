<?php
// Отдаёт игру только тем, у кого есть ключ. Остальные видят «страницы нет».
require __DIR__ . '/../../private/config.php';

if (ACCESS_KEY === 'ЗАМЕНИТЕ_НА_СВОЙ_ДЛИННЫЙ_КЛЮЧ' || strlen(ACCESS_KEY) < 12) {
    http_response_code(500);
    exit('Впишите свой ключ в private/config.php');
}

$key = $_GET['key'] ?? '';

if (!is_string($key) || !hash_equals(ACCESS_KEY, $key)) {
    http_response_code(404);   // делаем вид, что страницы нет
    exit('неверный ключ');
}

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');   // чтобы не индексировали
header('Referrer-Policy: no-referrer');      // ключ из адреса не утечёт на другие сайты
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');             // игру нельзя встроить в чужой сайт
readfile(__DIR__ . '/../../private/game.html');
