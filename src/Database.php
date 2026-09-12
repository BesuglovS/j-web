<?php

/**
 * Единый доступ к SQLite через PDO.
 */
class Database
{
    private static ?PDO $pdo = null;
    private static ?array $config = null;

    public static function init(): void
    {
        self::$config = config()['db'];
        $dsn = 'sqlite:' . self::$config['path'];

        try {
            self::$pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            if (self::$config['foreign_keys']) {
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
            }
            if (isset(self::$config['journal'])) {
                self::$pdo->exec('PRAGMA journal_mode = ' . self::$config['journal'] . ';');
            }
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Ошибка подключения к БД: ' . e($e->getMessage()));
        }
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::init();
        }
        return self::$pdo;
    }

    public static function close(): void
    {
        self::$pdo = null;
    }

    /**
     * Выполняет все миграции из migration.sql (идемпотентно).
     * Разбивает на отдельные SQL-выражения по ';'.
     */
    public static function migrate(): void
    {
        $file = config()['migration_file'];
        if (!is_file($file)) {
            return;
        }
        $sql = file_get_contents($file);
        $pdo = self::pdo();
        foreach (self::splitSql($sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }

        self::dropColumns($pdo, 'students', ['email', 'birth_date']);
        self::dropColumns($pdo, 'parents', ['email', 'phone']);
        self::dropColumns($pdo, 'subjects', ['teacher_name']);

        // Пересоздаём marks без UNIQUE(student_id, lesson_id, work_type):
        // разрешаем несколько оценок за урок (мобильное приложение).
        self::rebuildMarksTable($pdo);

        self::addColumn($pdo, 'students', 'external_id', 'INTEGER');
        self::addColumn($pdo, 'students', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
        // Родители — read-only зеркало auth-web (api/public_parents.php):
        // external_id = id профиля parents в auth-web.
        self::addColumn($pdo, 'parents', 'external_id', 'INTEGER');
        // Предмет привязан к классу; для существующих БД добавляем колонку
        // и проставляем класс из уже созданных занятий (без данных — NULL).
        self::addColumn($pdo, 'subjects', 'class_id', 'INTEGER');
        $pdo->exec('UPDATE subjects SET class_id = (SELECT l.class_id FROM lessons l WHERE l.subject_id = subjects.id LIMIT 1) WHERE class_id IS NULL');
        self::addColumn($pdo, 'classes', 'external_id', 'INTEGER');
        // Минуты опоздания для статуса 'late' (мобильное приложение).
        self::addColumn($pdo, 'attendance', 'late_minutes', 'INTEGER');
        // Переписывание оценок: каждая строка marks — попытка в группе
        // (student, предмет урока, work_type). is_retake=1 — попытка переписывания
        // (привязана к уроку исходной оценки); attempt_date — машиночитаемая дата
        // попытки (переписывания — дата пересдачи); is_current=1 — итоговая
        // (последняя по attempt_date) попытка группы, только она участвует в
        // средних. При добавлении колонок в существующую БД все оценки
        // считаем итоговыми.
        self::addColumn($pdo, 'marks', 'is_retake', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumn($pdo, 'marks', 'is_current', 'INTEGER NOT NULL DEFAULT 1');
        // Не в migration.sql: для существующих БД индекс создаётся только здесь,
        // после добавления колонок (иначе миграция падает на старой схеме).
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marks_current ON marks(student_id, is_current)');
        // Машиночитаемая дата попытки: обычная оценка — дата урока, переписывание —
        // дата пересдачи (приоритет «последней» попытки — по attempt_date).
        if (self::addColumn($pdo, 'marks', 'attempt_date', 'TEXT')) {
            // Бэкфилл: всем строкам дата урока; у переписываний — первое ДД.ММ.ГГГГ
            // из comment (раньше дата пересдачи вводилась в комментарий).
            $pdo->exec(
                "UPDATE marks SET attempt_date = (SELECT l.date FROM lessons l WHERE l.id = marks.lesson_id)
                 WHERE attempt_date IS NULL OR attempt_date = ''"
            );
            $rows = $pdo->query("SELECT id, comment FROM marks WHERE is_retake = 1")->fetchAll();
            foreach ($rows as $r) {
                $date = \MarkService::parseDateFromComment((string)($r['comment'] ?? ''));
                if ($date !== null) {
                    $upd = $pdo->prepare('UPDATE marks SET attempt_date=? WHERE id=?');
                    $upd->execute([$date, (int)$r['id']]);
                }
            }
        }
        $indexes = $pdo->query("PRAGMA index_list('classes')")->fetchAll();
        $hasClassIdx = false;
        foreach ($indexes as $ix) {
            if (($ix['name'] ?? '') === 'idx_classes_external') {
                $hasClassIdx = true;
                break;
            }
        }
        if (!$hasClassIdx) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_classes_external ON classes(external_id) WHERE external_id IS NOT NULL');
        }
        $studentIndexes = $pdo->query("PRAGMA index_list('students')")->fetchAll();
        $hasStudentIdx = false;
        foreach ($studentIndexes as $ix) {
            if (($ix['name'] ?? '') === 'idx_students_external') {
                $hasStudentIdx = true;
                break;
            }
        }
        if (!$hasStudentIdx) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_students_external ON students(external_id) WHERE external_id IS NOT NULL');
        }
        $parentIndexes = $pdo->query("PRAGMA index_list('parents')")->fetchAll();
        $hasParentIdx = false;
        foreach ($parentIndexes as $ix) {
            if (($ix['name'] ?? '') === 'idx_parents_external') {
                $hasParentIdx = true;
                break;
            }
        }
        if (!$hasParentIdx) {
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_parents_external ON parents(external_id) WHERE external_id IS NOT NULL');
        }
    }

    /**
     * Добавляет колонку, если её ещё нет (идемпотентно). Возвращает true,
     * если колонка была добавлена при этом вызове.
     */
    private static function addColumn(PDO $pdo, string $table, string $column, string $type): bool
    {
        $cols = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($cols as $c) {
            if ($c['name'] === $column) {
                return false;
            }
        }
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
        return true;
    }

    /**
     * Удаляет колонки из таблицы, если они существуют (идемпотентно).
     */
    private static function dropColumns(PDO $pdo, string $table, array $columns): void
    {
        $existing = [];
        $cols = $pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($cols as $c) {
            $existing[] = $c['name'];
        }
        foreach ($columns as $col) {
            if (in_array($col, $existing, true)) {
                $pdo->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $col);
            }
        }
    }

    /**
     * Пересоздаёт таблицу marks без UNIQUE(student_id, lesson_id, work_type),
     * если ограничение ещё есть (идемпотентно). Оценки со значением 1 не
     * переносятся: допускаются только 2–5.
     */
    private static function rebuildMarksTable(PDO $pdo): void
    {
        $sql = (string)$pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='marks'"
        )->fetchColumn();
        if ($sql === '' || stripos($sql, 'UNIQUE') === false) {
            return; // таблицы нет или уже без ограничения
        }
        $pdo->exec(
            'CREATE TABLE marks_new (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                student_id INTEGER NOT NULL,
                lesson_id  INTEGER NOT NULL,
                value      INTEGER CHECK (value BETWEEN 1 AND 5),
                work_type  TEXT NOT NULL DEFAULT \'lesson\',
                comment    TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
                FOREIGN KEY (lesson_id)  REFERENCES lessons(id)  ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'INSERT INTO marks_new (id, student_id, lesson_id, value, work_type, comment, created_at)
             SELECT id, student_id, lesson_id, value, work_type, comment, created_at FROM marks
             WHERE value IS NULL OR value >= 2'
        );
        $pdo->exec('DROP TABLE marks');
        $pdo->exec('ALTER TABLE marks_new RENAME TO marks');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marks_lesson  ON marks(lesson_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_marks_student ON marks(student_id)');
    }

    private static function splitSql(string $sql): array
    {
        // Простое разбиение по ';' — достаточно для нашей схемы (без функций/триггеров)
        return preg_split('/;\s*/', $sql) ?: [];
    }

    /**
     * Создаёт БД при первом запуске и применяет миграции.
     * Вызывается при первом обращении.
     */
    public static function ensureSchema(): void
    {
        $db = config()['db_path'];
        if (!is_file($db)) {
            $dir = dirname($db);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        self::migrate();
    }
}