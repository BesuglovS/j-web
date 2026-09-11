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

    // Внешние курсы: уроки/квизы python-web и задачи contest-web
    'python_admin_url' => 'https://python.nayanovaacademy.ru/sandbox/admin_quiz.php',
    'contest_admin_url' => 'https://contest.nayanovaacademy.ru/index.php?page=api&endpoint=admin_class_progress',
    // Эндпоинты «мой прогресс» (доступны ученикам под их SSO-сессией)
    'python_progress_url' => 'https://python.nayanovaacademy.ru/sandbox/progress.php',
    'contest_my_progress_url' => 'https://contest.nayanovaacademy.ru/index.php?page=api&endpoint=my_progress',
    'python_site_url' => 'https://python.nayanovaacademy.ru',
    // Метаданные уроков (номер → страница) — тот же файл, что собирает python-web
    'python_lessons_url' => 'https://python.nayanovaacademy.ru/lessons.json',
    'contest_site_url' => 'https://contest.nayanovaacademy.ru',
    // Всего уроков python-курса (для диапазона отображения в успеваемости)
    'python_max_lessons' => 50,
    // Соответствие урок python-курса → ID контеста в contest-web (источник истины — lessons.json,
    // генерируется скриптом build-config-meta.mjs; сюда копировать из python-web/sandbox/contest_map.php)
    'python_lesson_contests' => [
        8 => 7,
        10 => 8,
        12 => 10,
        15 => 9,
        17 => 12,
        19 => 13,
        21 => 14,
        22 => 11,
        25 => 16,
        26 => 15,
        27 => 17,
        28 => 20,
        29 => 18,
        30 => 19,
    ],

    // Логины/пароли учётных записей: по умолчанию создаётся админ.
    // Пароли хешируются при сиде; учётные данные в pass.md.
    'initial_admin' => [
        'login' => 'admin',
        // Hash пароля из pass.md (см. pass.md). При необходимости заменить:
        'password_hash' => '$2y$10$drSrRe2pb8O78nq0otpOT.lnZK0eOr3i93mOCbxQWoe9BYckMwdIC',
        'full_name' => 'Администратор системы',
    ],
];