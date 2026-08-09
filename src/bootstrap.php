<?php
/**
 * Точка входа для всех скриптов фреймворка.
 * Подключает конфиг, БД, сессию, автозагрузку и helpers.
 */

declare(strict_types=1);

$GLOBALS['APP_START'] = microtime(true);

require_once __DIR__ . '/helpers.php';

require __DIR__ . '/config.php';
$config = config();

spl_autoload_register(function (string $class) use ($config) {
    $base = $config['base_path'] . '/src/';
    // Имена с пространством имён Src\... → src/...
    if (str_starts_with($class, 'Src\\')) {
        $relative = substr($class, 4);
        $file = $base . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
    // Простые (без namespace) классы: пробуем несколько каталогов
    $candidates = [
        $base . '/' . $class . '.php',
        $base . '/Controllers/' . $class . '.php',
        $base . '/Services/' . $class . '.php',
        $base . '/Models/' . $class . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});

// БД
Database::init();

// Сессия
session_name($config['session_name']);
session_start();

// CSRF-токен
if (empty($_SESSION[$config['csrf_key']])) {
    $_SESSION[$config['csrf_key']] = bin2hex(random_bytes(16));
}

register_shutdown_function(function () {
    Database::close();
});