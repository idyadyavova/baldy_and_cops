<?php
// Загрузка картинки для поста. Отвечает JSON: {"url": "media/2026/10/….jpg"}.
// Принимаем только JPEG, PNG, WebP и GIF и проверяем содержимое, а не расширение.
// У JPEG с телефона убираем EXIF (там бывают координаты съёмки), поворот при этом сохраняется.
define('LAB_DEPTH', 1);
require __DIR__ . '/../../../private/lab/app/admin.php';

$s = admin_boot();   // без входа и без токена формы сюда не попасть
if (!is_post()) lab_json(['error' => 'Нужен POST'], 405);

$f = $_FILES['file'] ?? null;
if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) lab_json(['error' => 'Файл не выбран'], 400);
if ($f['error'] !== UPLOAD_ERR_OK) lab_json(['error' => lab_upload_error((int)$f['error'])], 400);
if ($f['size'] > min(25 * 1048576, lab_upload_limit())) lab_json(['error' => 'Картинка больше 25 МБ'], 400);

$info = @getimagesize($f['tmp_name']);
$types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
if (!$info || !isset($types[$info[2]])) lab_json(['error' => 'Это не картинка JPEG, PNG, WebP или GIF'], 400);
if ($info[0] > 12000 || $info[1] > 12000) lab_json(['error' => 'Слишком большое разрешение'], 400);
$ext = $types[$info[2]];

$rel = 'media/' . date('Y') . '/' . date('m');
$dir = LAB_WEB . '/' . $rel;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) lab_json(['error' => 'Не получилось создать папку media/ — проверьте права'], 500);
$name = bin2hex(random_bytes(8)) . '.' . $ext;
$dest = $dir . '/' . $name;

$saved = false;
if ($ext === 'jpg' && function_exists('imagecreatefromjpeg')) {
    // перекодируем: так из файла уходит EXIF, а большие снимки уменьшаются до 2400 px
    $img = @imagecreatefromjpeg($f['tmp_name']);
    if ($img) {
        $o = function_exists('exif_read_data') ? (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1) : 1;
        if (in_array($o, [3, 4], true)) $img = imagerotate($img, 180, 0);
        if (in_array($o, [5, 6], true)) $img = imagerotate($img, -90, 0);
        if (in_array($o, [7, 8], true)) $img = imagerotate($img, 90, 0);
        if (in_array($o, [2, 4, 5, 7], true) && function_exists('imageflip')) imageflip($img, IMG_FLIP_HORIZONTAL);
        $w = imagesx($img);
        $hgt = imagesy($img);
        if (max($w, $hgt) > 2400) {
            $k = 2400 / max($w, $hgt);
            $img = imagescale($img, (int)round($w * $k), (int)round($hgt * $k), IMG_BICUBIC) ?: $img;
        }
        imageinterlace($img, true);
        $saved = imagejpeg($img, $dest, 86);
        imagedestroy($img);
    }
}
if (!$saved && !move_uploaded_file($f['tmp_name'], $dest)) lab_json(['error' => 'Не получилось сохранить файл'], 500);
@chmod($dest, 0644);

lab_audit((int)$s['user_id'], 'media', $rel . '/' . $name);
lab_json(['url' => $rel . '/' . $name, 'src' => lab_base() . $rel . '/' . $name]);
