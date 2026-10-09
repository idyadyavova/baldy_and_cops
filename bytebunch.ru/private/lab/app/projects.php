<?php
// HTML-проекты: хранение файлов вне public_html и разбор загрузок (один HTML или ZIP-архив).
//
// Файлы проекта лежат в private/lab/data/projects/<id>/ и отдаются только через p/index.php,
// который проверяет доступ. Исполняемые файлы (php, phtml, cgi…) и скрытые файлы (.htaccess) из архива не берём.

require_once __DIR__ . '/core.php';

const LAB_PROJECT_MAX_FILES = 3000;
const LAB_PROJECT_MAX_BYTES = 300 * 1048576;   // 300 МБ после распаковки

/** Какие файлы можно загрузить и с каким типом их отдавать. */
function lab_project_types(): array
{
    $text = '; charset=utf-8';
    return [
        'html' => 'text/html' . $text, 'htm' => 'text/html' . $text,
        'css' => 'text/css' . $text, 'js' => 'text/javascript' . $text, 'mjs' => 'text/javascript' . $text,
        'json' => 'application/json' . $text, 'map' => 'application/json' . $text, 'webmanifest' => 'application/manifest+json' . $text,
        'txt' => 'text/plain' . $text, 'md' => 'text/plain' . $text, 'csv' => 'text/csv' . $text, 'tsv' => 'text/tab-separated-values' . $text,
        'xml' => 'application/xml' . $text, 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'avif' => 'image/avif', 'ico' => 'image/x-icon', 'bmp' => 'image/bmp',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'flac' => 'audio/flac',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime',
        'pdf' => 'application/pdf', 'wasm' => 'application/wasm', 'zip' => 'application/zip',
        'glb' => 'model/gltf-binary', 'gltf' => 'model/gltf+json',
        'bin' => 'application/octet-stream', 'onnx' => 'application/octet-stream', 'tflite' => 'application/octet-stream',
        'data' => 'application/octet-stream', 'npy' => 'application/octet-stream', 'pt' => 'application/octet-stream',
    ];
}

function lab_project_ext(string $name): string
{
    return strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
}

function lab_project_dir(int $id): string
{
    return lab_data_dir('projects') . '/' . $id;
}

/** Безопасный относительный путь внутри проекта или null. */
function lab_project_clean_path(string $rel): ?string
{
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || $rel[0] === '/' || preg_match('/[\x00-\x1f\x7f:*?"<>|]/', $rel)) return null;
    if (strlen($rel) > 400) return null;
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..' || $seg[0] === '.') return null;   // ни выхода наверх, ни скрытых файлов
    }
    return $rel;
}

/**
 * Сохраняет загруженный файл проекта во временную папку и меняет ею старую версию.
 * Возвращает ['entry' => …, 'size' => …, 'count' => …, 'skipped' => [...]]. Бросает RuntimeException с понятным текстом.
 */
function lab_project_store(int $id, array $upload): array
{
    if (!isset($upload['error']) || is_array($upload['error'])) throw new RuntimeException('Файл не выбран.');
    if ($upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(lab_upload_error((int)$upload['error']));
    if (!is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Файл не загрузился.');

    $name = (string)$upload['name'];
    $ext = lab_project_ext($name);
    $root = lab_data_dir('projects');
    $tmp = $root . '/.tmp-' . bin2hex(random_bytes(6));
    if (!@mkdir($tmp, 0700)) throw new RuntimeException('Не получилось создать папку для проекта — проверьте права на private/lab/data.');

    try {
        if ($ext === 'html' || $ext === 'htm') {
            if ($upload['size'] > 50 * 1048576) throw new RuntimeException('HTML-файл больше 50 МБ — упакуйте проект в ZIP.');
            if (!move_uploaded_file($upload['tmp_name'], $tmp . '/index.html')) throw new RuntimeException('Не получилось сохранить файл.');
            $res = ['entry' => 'index.html', 'size' => (int)filesize($tmp . '/index.html'), 'count' => 1, 'skipped' => []];
        } elseif ($ext === 'zip') {
            $res = lab_project_unzip($upload['tmp_name'], $tmp);
        } else {
            throw new RuntimeException('Загрузите .html или .zip с проектом (index.html внутри).');
        }
        $final = $root . '/' . $id;
        $old = null;
        if (is_dir($final)) {
            $old = $root . '/.old-' . bin2hex(random_bytes(6));
            rename($final, $old);
        }
        if (!rename($tmp, $final)) {
            if ($old) rename($old, $final);
            throw new RuntimeException('Не получилось заменить файлы проекта.');
        }
        if ($old) lab_rmtree($old);
        return $res;
    } catch (Throwable $e) {
        lab_rmtree($tmp);
        throw $e;
    }
}

function lab_project_unzip(string $zipFile, string $dest): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('На хостинге выключен модуль zip. Включите его в настройках PHP или загрузите один HTML-файл.');
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('Не получилось открыть ZIP-архив.');

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if (!$st) continue;
        $n = str_replace('\\', '/', (string)$st['name']);
        if ($n === '' || substr($n, -1) === '/') continue;                          // папка
        if (strpos($n, '__MACOSX/') === 0 || preg_match('~(^|/)(\.DS_Store|Thumbs\.db|desktop\.ini)$~i', $n)) continue;
        $entries[$i] = ['name' => $n, 'size' => (int)$st['size']];
    }
    if (!$entries) throw new RuntimeException('Архив пустой.');
    if (count($entries) > LAB_PROJECT_MAX_FILES) throw new RuntimeException('В архиве больше ' . LAB_PROJECT_MAX_FILES . ' файлов.');

    // если всё лежит в одной папке (project/index.html…) — убираем её из путей
    $first = explode('/', reset($entries)['name'])[0] . '/';
    $strip = $first;
    foreach ($entries as $e) {
        if (strpos($e['name'], $strip) !== 0) {
            $strip = '';
            break;
        }
    }

    $types = lab_project_types();
    $total = 0;
    $count = 0;
    $skipped = [];
    $htmls = [];
    foreach ($entries as $i => $e) {
        $rel = lab_project_clean_path(substr($e['name'], strlen($strip)));
        $ext = $rel ? lab_project_ext($rel) : '';
        if ($rel === null || !isset($types[$ext])) {
            $skipped[] = $e['name'];
            continue;
        }
        $total += $e['size'];
        if ($total > LAB_PROJECT_MAX_BYTES) throw new RuntimeException('После распаковки проект больше ' . lab_bytes(LAB_PROJECT_MAX_BYTES) . '.');

        $target = $dest . '/' . $rel;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) throw new RuntimeException('Не получилось создать папку ' . dirname($rel));
        $in = $zip->getStream($zip->getNameIndex($i));
        $out = @fopen($target, 'wb');
        if (!$in || !$out) throw new RuntimeException('Не получилось распаковать ' . $rel);
        // считаем реальные байты: размер в заголовке архива можно подделать («zip-бомба»)
        $written = 0;
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) break;
            $written += strlen($chunk);
            if ($written > $e['size'] + 1024 || $total - $e['size'] + $written > LAB_PROJECT_MAX_BYTES) {
                fclose($in);
                fclose($out);
                throw new RuntimeException('Архив повреждён или распаковывается в слишком большой объём.');
            }
            fwrite($out, $chunk);
        }
        fclose($in);
        fclose($out);
        $count++;
        if ($ext === 'html' || $ext === 'htm') $htmls[] = $rel;
    }
    $zip->close();

    if (!$htmls) throw new RuntimeException('В архиве нет HTML-файла. Нужен index.html.');
    return ['entry' => lab_project_pick_entry($htmls), 'size' => $total, 'count' => $count, 'skipped' => array_slice($skipped, 0, 20)];
}

/** Главная страница проекта: index.html в корне, иначе самый «верхний» HTML. */
function lab_project_pick_entry(array $htmls): string
{
    foreach (['index.html', 'index.htm'] as $n) if (in_array($n, $htmls, true)) return $n;
    usort($htmls, function ($a, $b) {
        return [substr_count($a, '/'), $a] <=> [substr_count($b, '/'), $b];
    });
    return $htmls[0];
}

/** Список файлов проекта для панели. */
function lab_project_files(int $id, int $limit = 300): array
{
    $dir = lab_project_dir($id);
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $out[] = ['path' => substr(str_replace('\\', '/', $f->getPathname()), strlen($dir) + 1), 'size' => $f->getSize()];
        if (count($out) >= $limit) break;
    }
    usort($out, function ($a, $b) { return strcmp($a['path'], $b['path']); });
    return $out;
}
