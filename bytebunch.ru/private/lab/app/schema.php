<?php
// Таблицы панели и первая установка.

function lab_schema_sql(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'CREATE TABLE IF NOT EXISTS ' . T('meta') . ' (
            k VARCHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('users') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(64) NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            totp_secret VARCHAR(64) NULL,
            totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            totp_last BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            pass_changed_at DATETIME NOT NULL,
            UNIQUE KEY login (login)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('sessions') . ' (
            id CHAR(64) CHARACTER SET ascii NOT NULL PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            csrf CHAR(43) CHARACTER SET ascii NOT NULL,
            mfa_pending TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            ua VARCHAR(255) NOT NULL,
            KEY user_id (user_id)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('attempts') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(16) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            subject VARCHAR(64) NOT NULL DEFAULT \'\',
            ok TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY by_ip (kind, ip, created_at),
            KEY by_subject (kind, subject, created_at)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('posts') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL,
            title VARCHAR(200) NOT NULL,
            tag VARCHAR(40) NOT NULL DEFAULT \'\',
            summary TEXT NOT NULL,
            body MEDIUMTEXT NOT NULL,
            link VARCHAR(500) NOT NULL DEFAULT \'\',
            cover VARCHAR(200) NOT NULL DEFAULT \'\',
            cover_caption VARCHAR(300) NOT NULL DEFAULT \'\',
            status VARCHAR(12) NOT NULL DEFAULT \'draft\',
            featured TINYINT(1) NOT NULL DEFAULT 0,
            date_label VARCHAR(40) NOT NULL DEFAULT \'\',
            published_at DATE NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY slug (slug),
            KEY listing (status, published_at)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('projects') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NOT NULL,
            visibility VARCHAR(12) NOT NULL DEFAULT \'private\',
            access_key VARCHAR(64) CHARACTER SET ascii NOT NULL,
            entry VARCHAR(255) NOT NULL DEFAULT \'index.html\',
            sandbox TINYINT(1) NOT NULL DEFAULT 1,
            listed TINYINT(1) NOT NULL DEFAULT 0,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            files_count INT UNSIGNED NOT NULL DEFAULT 0,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY slug (slug)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('api_keys') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            prefix VARCHAR(16) CHARACTER SET ascii NOT NULL,
            key_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            scopes VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            UNIQUE KEY key_hash (key_hash)
        )' . $tail,
        'CREATE TABLE IF NOT EXISTS ' . T('audit') . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            action VARCHAR(40) NOT NULL,
            details VARCHAR(500) NOT NULL DEFAULT \'\',
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY created_at (created_at)
        )' . $tail,
    ];
}

/** Состояние установки: 'noconfig' | 'nodb' | 'empty' (нет таблиц или админа) | 'ready'. */
function lab_install_state(?string &$error = null): string
{
    if (!lab_config()) return 'noconfig';
    try {
        lab_db();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return 'nodb';
    }
    if (!lab_meta('schema')) return 'empty';
    try {
        $n = (int)q('SELECT COUNT(*) FROM ' . T('users'))->fetchColumn();
    } catch (Throwable $e) {
        return 'empty';
    }
    return $n > 0 ? 'ready' : 'empty';
}

function lab_install(string $login, string $password): int
{
    $pdo = lab_db();
    foreach (lab_schema_sql() as $sql) $pdo->exec($sql);
    if (!lab_meta('secret')) lab_meta_set('secret', bin2hex(random_bytes(32)));
    lab_meta_set('schema', (string)LAB_SCHEMA);

    q('INSERT INTO ' . T('users') . ' (login, pass_hash, created_at, pass_changed_at) VALUES (?, ?, ?, ?)',
        [$login, lab_hash_password($password), lab_now(), lab_now()]);
    $uid = (int)$pdo->lastInsertId();

    if ((int)q('SELECT COUNT(*) FROM ' . T('posts'))->fetchColumn() === 0) lab_seed_posts();
    lab_data_dir('projects');
    lab_audit($uid, 'setup', 'Панель установлена, создан вход «' . $login . '»');
    return $uid;
}

/** Новости, которые уже были на главной, — чтобы после установки сайт выглядел как раньше. */
function lab_seed_posts(): void
{
    $posts = [
        ['aria-0-6-2', '*Aria* 0.6.2', 'Релиз', 'Стрелка тюнера больше не застревает после сильного щипка, а для тех, кто строится по GuitarTuna, появился режим «Строй как в GuitarTuna». Все изменения проверены на записях настоящей гитары.',
            'aria/#new', 'aria-dial', 'Точно настроенная струна E2: Aria показывает 0 центов, GuitarTuna — +16.', 1, '', '2026-10-05'],
        ['pochemu-tyunery-oshibayutsya-na-basah', 'Почему тюнеры ошибаются на басах', 'Исследование', 'Жёсткость струны сдвигает гармоники вверх. Сравнили Aria с GuitarTuna и 18 открытыми алгоритмами.',
            'aria/#bass', '', '', 0, '', '2026-10-05'],
        ['sionika-3-0-beta', 'Sionika 3.0 beta', 'Релиз', 'Встроенный тюнер на движке Aria, выбор версии движка и строй как в GuitarTuna.',
            'sionika/', '', '', 0, '10.2026', '2026-10-02'],
        ['proverka-na-zhivoy-gitare', 'Проверка на живой гитаре', 'Исследование', 'Записали басовые и средние струны и сравнили показания тюнеров с гармониками самой струны.',
            'aria/#real', '', '', 0, '', '2026-10-01'],
        ['github-laboratorii', 'GitHub лаборатории', 'Анонс', 'Скоро у ByteBunch • Lab появится GitHub.',
            '', '', '', 0, 'скоро', '2026-09-30'],
        ['pervaya-versiya-sionika', 'Первая версия Sionika', 'Релиз', 'Песенник с аккордами — первый проект лаборатории.',
            'sionika/#history', '', '', 0, '', '2025-06-05'],
    ];
    foreach ($posts as $p) {
        q('INSERT INTO ' . T('posts') . ' (slug, title, tag, summary, body, link, cover, cover_caption, status, featured, date_label, published_at, created_at, updated_at)
           VALUES (?, ?, ?, ?, \'\', ?, ?, ?, \'published\', ?, ?, ?, ?, ?)',
            [$p[0], $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7], $p[8], $p[9], lab_now(), lab_now()]);
    }
}
