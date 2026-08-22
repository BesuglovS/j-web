# AGENTS.md — Инструкции для ИИ-ассистентов

Электронный журнал преподавателя («Журнал информационных технологий») для Nayanova Academy.
Несмотря на Vite/Tailwind-инструментарий, это **серверный PHP 8.1+ без фреймворков и Composer**,
БД — SQLite, фронтенд — Tailwind v4 (сборка Vite только для CSS). Прод: `https://j.nayanovaacademy.ru`.

## ⚠️ Критические правила

1. **Проект PHP, а не JS.** Node-инструментарий только компилирует CSS и копирует `tracking-client.js`.
   Не пишите серверную логику на JS.
2. **Классы и ученики — read-only зеркала auth-web.** Журнал НЕ создаёт/редактирует/удаляет их;
   синхронизация аддитивная (upsert по `external_id`, никогда не удаляет — иначе осиротеют оценки/уроки).
   Журнал владеет: предметы, четверти, уроки, оценки, ДЗ, замечания, родители и связи родитель–ученик.
3. **`db/app.db` — живая прод-БД.** Не редактировать и не коммитить; регенерируется из `db/migration.sql`
   при первом запросе. Сброс = удалить файл.
4. **`migration.sql` должен оставаться идемпотентным и безопасным к `;`**: `Database::migrate()` наивно
   режет SQL по `;` — никаких триггеров/функций/процедур с внутренними точками с запятой. Эволюция схемы
   существующих БД — через программные `addColumn()`/`dropColumns()` в `Database::migrate()`.
5. **`public/assets/` генерируется Vite** — правки только в `assets-src/`, затем `npm run build`.
   1-байтовый `app.js` — норма. Свежий чекаут без сборки не деплоится (в шаблонах ссылка на
   `/assets/tracking-client.js`, который создаёт только сборка).
6. **Каждая POST-форма обязана нести CSRF**: `csrf_field()` в шаблонах, `verify_csrf()`/`csrfGuard()` в контроллерах.
7. **Auth-модель раздвоена**: admin и student входят через SSO auth-web (кука `auth_session`, локального
   пароля нет); parent входит локально (`users.role='parent'`, bcrypt). Не ломайте разделение.
8. **Seed-хэш первого админа захардкожен** в `config.php` и `migration.sql` — менять их нужно ОБА и синхронно.
9. **`AuthClient.php` и `assets-src/public/tracking-client.js` — копии канонических источников auth-web**
   (`na-web/shared/`); не расходиться, синхронизировать.
10. **`src/`, `db/`, `templates/`, `data/` обязаны оставаться вне `public/`** (webroot). nginx блокирует
    скрытые файлы и `.(sql|db|md|log)$`.
11. **`.env` читается только `deploy.ps1`** — не печатать значения. `pass.md` (gitignored) хранит пароль админа — не коммитить.

## 🔧 Команды

```bash
cd assets-src
npm install && npm run build   # Vite → ../public/assets (app.css/app.js)
npm run dev                    # Vite dev-сервер

cd ..
php -S 127.0.0.1:8090 -t public public/index.php   # локальный dev (PHP 8.1+)
.\deploy.ps1 -DryRun           # сухой прогон
.\deploy.ps1                   # деплой (или -SkipBuild)
```

Тестов, линтера и CI **нет** — изменения в `Database::migrate()`, синк-сервисах и auth-потоке
проверяются вручную и несут повышенный риск.

## 🏗 Структура

```
public/index.php           # фронт-контроллер (WEBROOT)
public/assets/             # ГЕНЕРИРУЕТСЯ Vite: app.css, app.js, tracking-client.js
src/bootstrap.php          # lifecycle: helpers, config(), spl_autoload_register, Database::init(), сессия journal_sid, CSRF, shutdown
src/config.php             # пути, опции БД, session_name, csrf_key, URL auth-web, seed-хэш
src/routes.php             # ~50 маршрутов; Router::dispatch() → Controller::action($params) (позиционный массив!)
src/Router.php, Database.php, Auth.php, AuthClient.php, View.php, helpers.php
src/Controllers/           # AdminController, AuthController, DashboardController, StudentController, ParentController
src/Services/              # GroupService, StudentService, GradeService, ImportService
src/Models/                # ПУСТО и не используется — не подразумевайте слой моделей
db/migration.sql           # схема (идемпотентная); db/app.db — живая БД (gitignored)
templates/                 # plain-PHP шаблоны (Tailwind): layouts/ app.php guest.php; auth/, admin/, my/, parent/
assets-src/                # Vite root: entries/ (app.js, app.css), public/tracking-client.js, vite.config.js
data/                      # CSV-шаблоны импорта (parents.csv, links.csv)
runtime/                   # логи/tmp (gitignored; на сервере обязана существовать)
deploy.ps1, j.nayanovaacademy.ru (nginx)
```

## 📊 Схема БД (ключевое)

`users` (login UNIQUE, password_hash, role admin|student|parent), `classes` (external_id = auth-web group id),
`subjects` (привязан к class), `students` (external_id = auth-web user id), `parents`, `student_parent`,
`quarters`, `lessons`, `homeworks`, `marks` (1–5; `UNIQUE(student_id, lesson_id, work_type)` + upsert),
`homework_submissions`, `lesson_remarks`, `import_logs`.

## 💻 Конвенции кода

- **PHP**: `declare(strict_types=1);` в каждом файле; классы без namespace (кроме опц. `Src\`);
  методы `snake_case`; типизация параметров/возвратов.
- **Шаблоны**: `$var = $var ?? [];` для всех инжектов; вывод через `e()` и `(int)`; альтернативный
  синтаксис `<?php if ... ?> ... <?php endif; ?>`; `<?=` для вывода.
- **SQL**: PDO подготовленные statements (`?`/named), `ON CONFLICT ... DO UPDATE` для upsert, `INSERT OR IGNORE` для связей.
- **CSS**: Tailwind v4, `@source` на `templates/**` и `src/**` (чтобы все утилиты скомпилировались),
  компонентные классы в `@layer components`.
- **JS**: минимально; только tracking-client (IIFE, `window.NayanovaTrack`).
- Комментарии и UI — **русский**.

## 🚀 Деплой (`deploy.ps1`)

1. `.env` → SSH-переменные (`DEPLOY_SSH_HOST/PORT/USER/KEY/REMOTE_PATH`); `icacls` ключа.
2. `-SkipBuild` пропускает `npm install`/`npm run build` в `assets-src/`.
3. Проверяет наличие всех шести директорий (`public, src, templates, data, db, runtime`).
4. `tar` (без `node_modules`, `__pycache__`, `app.db*`) → SSH. Удалённо: бэкап `db/`+`runtime/` в `/tmp` →
   `rm -rf` webroot → восстановление данных → распаковка → `chown www-data:www-data` + `chmod 775`.
5. Деплой nginx-конфига + `nginx -t && systemctl reload nginx`.

⚠️ Известный баг `deploy.ps1`: секция nginx использует `$portArg`, который нигде не объявлен — на
нестандартном SSH-порту nginx-деплой молча уйдёт на 22. Учитывать при работе с портами.

## 🔒 Безопасность

- Никогда не печатать/коммитить `.env` и `ssh-private.key` (`G:\WebSites\na\`).
- `ParentController::child()` явно проверяет принадлежность ребёнка родителю (иначе 403) — не ослаблять.
- Разлогин SSO-пользователей редиректит на портал `/api/logout.php`.
- Рендер через `extract()` — избегать коллизий имён между данными страницы и переменными лейаута.
- CSV-импорт (`data/parents.csv`, `data/links.csv`): разделители `;` или `,`; ученики НЕ импортируются.