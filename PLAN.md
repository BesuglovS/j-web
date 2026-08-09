# План разработки: Электронный журнал преподавателя

## Цель
PHP-сайт для ведения журнала занятий преподавателя. Данные хранятся в SQLite.
Доступ: 3 роли — администратор (всё), ученик (о себе), родитель (о своих детях).

## Целевое окружение (продакшн)
- Домен: `j.nayanovaacademy.ru` (nginx + PHP-FPM 8.1, SSL)
- Webroot: `/var/www/j.nayanovaacademy.ru/public`
- БД: SQLite (файл `db/app.db` вне webroot)
- Сборка UI: на этом Windows-ПК (Tailwind + Vite), загрузка артефактов на сервер

## Стек
- PHP 8.1+ (чистый PHP, без composer-фреймворка), PDO/SQLite
- Фронт-контроллер: `public/index.php` + собственный роутер
- UI: Tailwind CSS + Vite (сборка → `public/assets`)

## Структура проекта
```
j-web/
├─ public/                 # Webroot
│  ├─ index.php            # фронт-контроллер
│  └─ assets/              # собранные CSS/JS (Vite)
├─ src/                    # PHP-код
│  ├─ bootstrap.php        # конфиг, сессии, автозагрузка
│  ├─ Router.php
│  ├─ Database.php         # PDO + миграции
│  ├─ Auth.php             # вход/роли/смена пароля
│  ├─ Controllers/
│  ├─ Services/
│  └─ helpers.php
├─ db/
│  ├─ migration.sql
│  └─ app.db               # SQLite (вне public!)
├─ templates/              # HTML-шаблоны
├─ runtime/                # логи, временные файлы импорта
├─ assets-src/             # исходники Tailwind/Vite
├─ data/                   # шаблоны CSV для импорта
├─ pass.md                 # учётные данные первого администратора
└─ README.md               # инструкция по сборке и деплою
```

## Схема БД (таблицы)
- users(id, login UNIQUE, password_hash, role, full_name, created_at)
- classes(id, name, grade, school_year)
- subjects(id, class_id → classes, name, short_name)
- students(id, user_id, class_id, last_name, first_name, middle_name)
- parents(id, user_id, last_name, first_name, middle_name, email, phone)
- student_parent(student_id, parent_id)   # родитель ↔ дети (м-к-м)
- lessons(id, subject_id, class_id, date, start_time, topic, lesson_type, note)
- homeworks(id, lesson_id, title, description, due_date)
- marks(id, student_id, lesson_id, value, work_type, comment, created_at)
- homework_submissions(id, homework_id, student_id, status, result_mark, comment, submitted_at)
- lesson_remarks(id, lesson_id, student_id, text, created_at)
- quarters(id, name, start_date, end_date)
- import_logs(id, filename, kind, rows_ok, rows_fail, created_at)

## Роли и интерфейсы
- **Админ** `/admin/*`: CRUD справочников (предметы, периоды, родители, связки), журнал занятий (уроки, оценки, ДЗ + результаты, замечания), учётные записи, сброс пароля, смена своего пароля. Классы и студенты не редактируются — они синхронизируются только для чтения из auth-web (Классы ← «Группы», Студенты ← «Пользователи» и состав групп).
- **Ученик** `/my/*`: свои оценки + средний балл, ДЗ и отметка о выполнении, свои замечания, смена пароля.
- **Родитель** `/parent/*`: информация по всем своим детям (выбор ребёнка): оценки, ДЗ, замечания, смена пароля.

## Итоговые оценки
Среднее арифметическое `marks` (и результатов ДЗ) по предмету за период (`quarters`, фильтр по `lessons.date`).

## CSV-импорт
Шаблоны в `data/`: родители, связи студент-родитель.
Загрузка через интерфейс админа, отчёт об ошибках, логи в `import_logs`.
Классы и студенты импорту не подлежат: они ведутся в auth-web
и синхронизируются в журнал только для чтения (GroupService, StudentService).

## Безопасность
- password_hash / password_verify
- Prepared statements (PDO)
- CSRF-токены на всех POST-формах
- Проверка принадлежности данных роли (WHERE user_id = ?)
- db/ и runtime/ вне webroot, запрет доступа к скрытым файлам в nginx

## Этапы (порядок реализации)
1. Каркас: структура, роутер, bootstrap, Database, миграция, сид админа ✅
2. Аутентификация и роли ✅ (SSO через auth.nayanovaacademy.ru + локальный вход родителей)
3. CRUD справочников (admin) ✅ (предметы, периоды, родители, связи; классы и студенты — read-only из auth-web)
4. Журнал занятий (admin) ✅ (уроки, оценки с комментариями, ДЗ + результаты, замечания)
5. Личный интерфейс ученика и родителя ✅ (/my и /parent)
6. Смена пароля в интерфейсе ✅ (родители локально; админ/ученик — на портале)
7. CSV-импорт ✅ (студенты, родители, связи; журнал импорта)
8. Итоги за четверть ✅ (сводка средних оценок с фильтрами класс/период/предмет)
9. UI Tailwind+Vite (сборка на Windows-ПК) ✅ (public/assets генерируется npm run build)
10. Деплой: правки nginx, права, README ✅ (deploy.ps1 + конфиг j.nayanovaacademy.ru)

## Деплой на Ubuntu (кратко)
- Копирование public/ → /var/www/j.nayanovaacademy.ru/public, остальное в защищённую папку
- nginx: перенаправление всех запросов на /public/index.php
- Права: PHP должен писать в db/ и runtime/
- Собрать assets: `npm install && npm run build` на Windows, загрузить public/assets
