<?php
// Глобальная конфигурация приложения.
// OS: win32 (сборка) / Ubuntu (продакшн). Пути вычисляются автоматически.

return [
    // Корень приложения (папка выше public/)
    'base_path'      => dirname(__DIR__),

    // Пути
    'public_path'    => dirname(__DIR__) . '/public',
    'db_path'        => dirname(__DIR__) . '/db/app.db',
    'migration_file' => dirname(__DIR__) . '/db/migration.sql',
    'runtime_path'   => dirname(__DIR__) . '/runtime',
    'templates_path' => dirname(__DIR__) . '/templates',
    'data_path'      => dirname(__DIR__) . '/data',

    // БД
    'db' => [
        'path'         => dirname(__DIR__) . '/db/app.db',
        'foreign_keys' => true,
        'journal'      => 'WAL',
    ],

    // Параметры
    'app_name'     => 'Журнал информационных технологий',
    'session_name' => 'journal_sid',
    'csrf_key'     => '_csrf',

    // Единая система авторизации (auth-web)
    'auth_url' => 'https://auth.nayanovaacademy.ru',
    // Endpoint авторитетных групп/классов в auth-web (единый источник)
    'auth_groups_url' => 'https://auth.nayanovaacademy.ru/api/groups.php',
    // Endpoint пользователей auth-web (для синхронизации списка студентов)
    'auth_users_url' => 'https://auth.nayanovaacademy.ru/api/public_users.php',
    // Endpoint принадлежности пользователей к группам auth-web
    'auth_memberships_url' => 'https://auth.nayanovaacademy.ru/api/user_groups.php',

    // Логины/пароли учётных записей: по умолчанию создаётся админ.
    // Пароли хешируются при сиде; учётные данные в pass.md.
    'initial_admin' => [
        'login' => 'admin',
        // Hash пароля из pass.md (см. pass.md). При необходимости заменить:
        'password_hash' => '$2y$10$drSrRe2pb8O78nq0otpOT.lnZK0eOr3i93mOCbxQWoe9BYckMwdIC',
        'full_name' => 'Администратор системы',
    ],
];