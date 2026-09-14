-- ==========================================================
-- Схема БД журнала занятий преподавателя (SQLite)
-- Идемпотентно: можно применять многократно.
-- ==========================================================

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    login         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('admin','student','parent')),
    full_name     TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS classes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    grade       INTEGER,
    school_year TEXT,
    external_id INTEGER
);

-- Классы больше не редактируются в журнале: единый источник — auth-web
-- (api/groups.php). external_id = id группы auth-web, по нему идёт синхронизация.
-- Уникальный индекс idx_classes_external создаётся в Database::migrate()
-- (после идемпотентного добавления колонки external_id к существующим БД).

-- Предмет привязан к классу (например, «Информатика» у 9 «А»).
-- Преподаватель во всём проекте один — администратор, поэтому teacher_name не хранится.
CREATE TABLE IF NOT EXISTS subjects (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    class_id     INTEGER NOT NULL,
    name         TEXT NOT NULL,
    short_name   TEXT,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    class_id    INTEGER NOT NULL,
    last_name   TEXT NOT NULL,
    first_name  TEXT NOT NULL,
    middle_name TEXT,
    external_id INTEGER,
    FOREIGN KEY (user_id)  REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id)   ON DELETE CASCADE
);

-- Студенты больше не редактируются в журнале: единый источник — auth-web
-- (пользователь + принадлежность к группе). external_id = id пользователя
-- auth-web, по нему идёт синхронизация. Уникальный индекс idx_students_external
-- создаётся в Database::migrate() (после идемпотентного добавления колонки
-- external_id к существующим БД).

CREATE TABLE IF NOT EXISTS parents (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    last_name   TEXT NOT NULL,
    first_name  TEXT NOT NULL,
    middle_name TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS student_classes (
    student_id INTEGER NOT NULL,
    class_id   INTEGER NOT NULL,
    PRIMARY KEY (student_id, class_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE
);

-- Тьюторы (классные руководители): роль резолвится в Auth::user() по
-- SSO-логину auth-web (своего входа/паролей в журнале нет, таблица users
-- не задействована). Привязка к классам — tutor_classes.
CREATE TABLE IF NOT EXISTS tutors (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    login      TEXT NOT NULL UNIQUE,
    full_name  TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tutor_classes (
    tutor_id INTEGER NOT NULL,
    class_id INTEGER NOT NULL,
    PRIMARY KEY (tutor_id, class_id),
    FOREIGN KEY (tutor_id) REFERENCES tutors(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS student_parent (
    student_id INTEGER NOT NULL,
    parent_id  INTEGER NOT NULL,
    PRIMARY KEY (student_id, parent_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id)  REFERENCES parents(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS quarters (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    start_date TEXT,
    end_date   TEXT
);

CREATE TABLE IF NOT EXISTS lessons (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_id  INTEGER NOT NULL,
    class_id    INTEGER NOT NULL,
    date        TEXT NOT NULL,
    start_time  TEXT,
    topic       TEXT,
    lesson_type TEXT,
    note        TEXT,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)   REFERENCES classes(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS homeworks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    lesson_id   INTEGER NOT NULL,
    title       TEXT,
    description TEXT,
    due_date    TEXT,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

-- Несколько оценок за урок: без UNIQUE(student_id, lesson_id, work_type).
-- Для существующих БД таблица пересоздаётся программно в Database::migrate().
-- Переписывание оценки: каждая строка — попытка в группе (student, предмет урока,
-- work_type). is_retake=1 — попытка переписывания (привязана к уроку исходной
-- оценки, дата пересдачи — дополнительно в comment). attempt_date — машиночитаемая
-- дата попытки: у обычной оценки это дата урока, у переписывания — введённая
-- вручную дата пересдачи («последняя попытка» = максимальная attempt_date).
-- is_current=1 — итоговая (последняя) попытка группы, только она участвует
-- в средних. Все три колонки для существующих БД добавляются программно
-- в Database::migrate().
CREATE TABLE IF NOT EXISTS marks (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id   INTEGER NOT NULL,
    lesson_id    INTEGER NOT NULL,
    value        INTEGER CHECK (value BETWEEN 1 AND 5),
    work_type    TEXT NOT NULL DEFAULT 'lesson',
    comment      TEXT,
    attempt_date TEXT,
    is_retake    INTEGER NOT NULL DEFAULT 0,
    is_current   INTEGER NOT NULL DEFAULT 1,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS homework_submissions (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    homework_id INTEGER NOT NULL,
    student_id  INTEGER NOT NULL,
    status      TEXT NOT NULL DEFAULT 'not_done'
                CHECK (status IN ('done','partial','not_done')),
    result_mark INTEGER CHECK (result_mark BETWEEN 1 AND 5),
    comment     TEXT,
    submitted_at TEXT,
    UNIQUE (homework_id, student_id),
    FOREIGN KEY (homework_id) REFERENCES homeworks(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)  REFERENCES students(id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS lesson_remarks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    lesson_id  INTEGER NOT NULL,
    student_id INTEGER NOT NULL,
    text       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id)  ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS attendance (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    lesson_id  INTEGER NOT NULL,
    status     TEXT NOT NULL DEFAULT 'present'
               CHECK (status IN ('present','absent','late')),
    comment    TEXT,
    late_minutes INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (student_id, lesson_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id)  ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_attendance_lesson  ON attendance(lesson_id);
CREATE INDEX IF NOT EXISTS idx_attendance_student ON attendance(student_id);

CREATE TABLE IF NOT EXISTS import_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    filename   TEXT NOT NULL,
    kind       TEXT NOT NULL,
    rows_ok    INTEGER NOT NULL DEFAULT 0,
    rows_fail  INTEGER NOT NULL DEFAULT 0,
    detail     TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Индексы
CREATE INDEX IF NOT EXISTS idx_students_class   ON students(class_id);
CREATE INDEX IF NOT EXISTS idx_students_user    ON students(user_id);
CREATE INDEX IF NOT EXISTS idx_sc_class         ON student_classes(class_id);
CREATE INDEX IF NOT EXISTS idx_sc_student       ON student_classes(student_id);
CREATE INDEX IF NOT EXISTS idx_parents_user     ON parents(user_id);
CREATE INDEX IF NOT EXISTS idx_sp_parent        ON student_parent(parent_id);
CREATE INDEX IF NOT EXISTS idx_tc_tutor         ON tutor_classes(tutor_id);
CREATE INDEX IF NOT EXISTS idx_tc_class         ON tutor_classes(class_id);
CREATE INDEX IF NOT EXISTS idx_lessons_class    ON lessons(class_id);
CREATE INDEX IF NOT EXISTS idx_lessons_subject  ON lessons(subject_id);
CREATE INDEX IF NOT EXISTS idx_marks_lesson     ON marks(lesson_id);
CREATE INDEX IF NOT EXISTS idx_marks_student    ON marks(student_id);
-- idx_marks_current создаётся программно в Database::migrate() (после
-- идемпотентного добавления колонок is_retake/is_current).
CREATE INDEX IF NOT EXISTS idx_hw_lesson        ON homeworks(lesson_id);
CREATE INDEX IF NOT EXISTS idx_hws_homework     ON homework_submissions(homework_id);
CREATE INDEX IF NOT EXISTS idx_hws_student      ON homework_submissions(student_id);
CREATE INDEX IF NOT EXISTS idx_remarks_lesson   ON lesson_remarks(lesson_id);
CREATE INDEX IF NOT EXISTS idx_remarks_student  ON lesson_remarks(student_id);

-- Seed первого администратора (см. pass.md). Не перезаписывает при повторном запуске.
INSERT OR IGNORE INTO users (login, password_hash, role, full_name)
VALUES ('admin', '$2y$10$drSrRe2pb8O78nq0otpOT.lnZK0eOr3i93mOCbxQWoe9BYckMwdIC', 'admin', 'Администратор системы');