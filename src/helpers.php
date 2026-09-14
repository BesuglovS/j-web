<?php

/**
 * Простые глобальные помощники.
 */

function &config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function csrf_token(): string
{
    return $_SESSION[config()['csrf_key']] ?? '';
}

function csrf_field(): string
{
    $t = csrf_token();
    return '<input type="hidden" name="' . config()['csrf_key'] . '" value="' . e($t) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token = $token ?? ($_POST[config()['csrf_key']] ?? '');
    return hash_equals(csrf_token(), (string)$token);
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function ue(?string $s): string
{
    return urlencode((string)$s);
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $key, string $value): void
{
    $_SESSION['flash'][$key] = $value;
}

function flash_get(string $key): ?string
{
    if (isset($_SESSION['flash'][$key])) {
        $v = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $v;
    }
    return null;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Человекочитаемое название типа работы («за что» поставлена оценка).
 * Значения: lesson (Урок), control (Контроль), homework (Домашнее),
 * answer (Ответ), ДЗ (оценки за ДЗ из мобильного приложения).
 * Неизвестные значения — переводы для: ДЗ (Домашнее задание), СР (Самостоятельная работа).
 */
function work_type_label(?string $type): string
{
    static $map = [
        'lesson'   => 'Урок',
        'control'  => 'Контроль',
        'homework' => 'Домашнее',
        'answer'   => 'Ответ',
        'ДЗ'       => 'ДЗ',
        'СР'       => 'СР',
    ];
    $t = trim((string)$type);
    return $map[$t] ?? ($t ?: 'Урок');
}

function today(): string
{
    return date('Y-m-d');
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 443) == 443;
    $scheme = $https ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function asset(string $path): string
{
    return '/assets/' . ltrim($path, '/');
}

function dbg(...$args): void
{
    error_log(print_r($args, true));
}