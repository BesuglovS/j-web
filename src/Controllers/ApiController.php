<?php
declare(strict_types=1);

class ApiController
{
    private function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Cookie');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            exit;
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function requireAdmin(): void
    {
        $status = AuthClient::checkStatus();
        if ($status['status'] !== 'authenticated') {
            $this->json(['error' => 'Не авторизован'], 401);
        }
        $user = $status['user'];
        if (empty($user['is_admin'])) {
            $this->json(['error' => 'Доступ запрещён'], 403);
        }
    }

    private function input(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    public function me(): void
    {
        $status = AuthClient::checkStatus();
        if ($status['status'] !== 'authenticated') {
            $this->json(['error' => 'Не авторизован'], 401);
        }
        $this->json(['user' => $status['user']]);
    }

    public function classes(): void
    {
        $this->requireAdmin();
        GroupService::ensureSynced();
        $rows = Database::pdo()->query(
            'SELECT c.*, (SELECT COUNT(*) FROM student_classes sc JOIN students s ON s.id=sc.student_id WHERE sc.class_id=c.id AND s.is_active=1) AS student_count
             FROM classes c ORDER BY c.grade, c.name'
        )->fetchAll();
        $this->json(['classes' => $rows]);
    }

    public function classSubjects(array $params): void
    {
        $this->requireAdmin();
        $classId = (int)$params[0];
        $st = Database::pdo()->prepare('SELECT * FROM subjects WHERE class_id=? ORDER BY name');
        $st->execute([$classId]);
        $this->json(['subjects' => $st->fetchAll()]);
    }

    public function lessons(): void
    {
        $this->requireAdmin();
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $date = trim((string)($_GET['date'] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = '';
        }
        $pdo = Database::pdo();
        $sql = 'SELECT l.*, c.name AS class_name, s.name AS subject_name
                FROM lessons l
                JOIN classes c ON c.id=l.class_id
                JOIN subjects s ON s.id=l.subject_id';
        $where = [];
        $args = [];
        if ($classId) { $where[] = 'l.class_id=?'; $args[] = $classId; }
        if ($subjectId) { $where[] = 'l.subject_id=?'; $args[] = $subjectId; }
        if ($date !== '') { $where[] = 'l.date=?'; $args[] = $date; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        // Для конкретной даты — хронологический порядок по времени начала.
        $sql .= $date !== ''
            ? ' ORDER BY l.start_time ASC, l.id ASC'
            : ' ORDER BY l.date DESC, l.start_time DESC';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $this->json(['lessons' => $st->fetchAll()]);
    }

    public function lessonDetail(array $params): void
    {
        $this->requireAdmin();
        $id = (int)$params[0];
        $pdo = Database::pdo();
        $lesson = $pdo->prepare(
            'SELECT l.*, c.name AS class_name, s.name AS subject_name
             FROM lessons l JOIN classes c ON c.id=l.class_id JOIN subjects s ON s.id=l.subject_id WHERE l.id=?'
        );
        $lesson->execute([$id]);
        $lesson = $lesson->fetch();
        if (!$lesson) { $this->json(['error' => 'Урок не найден'], 404); }

        $students = $pdo->prepare('SELECT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name');
        $students->execute([$lesson['class_id']]);
        $students = $students->fetchAll();

        $marks = [];
        $st = $pdo->prepare('SELECT * FROM marks WHERE lesson_id=? ORDER BY id');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $m) {
            // Несколько оценок за урок: список на ученика
            $marks[$m['student_id']][] = $m;
        }

        $remarks = [];
        $st = $pdo->prepare('SELECT * FROM lesson_remarks WHERE lesson_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $remarks[$r['student_id']][] = $r;
        }

        $attendance = [];
        $st = $pdo->prepare('SELECT * FROM attendance WHERE lesson_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $a) {
            $attendance[$a['student_id']] = $a;
        }
        $homeworks = $pdo->prepare('SELECT * FROM homeworks WHERE lesson_id=?');
        $homeworks->execute([$id]);
        $homeworks = $homeworks->fetchAll();

        // ДЗ текущего урока (первое, ДЗ на следующий урок)
        $homework = $homeworks[0] ?? null;

        // ДЗ предыдущего урока (тот же класс и предмет), если оно там задано
        $previousHomework = null;
        $previousLessonDate = null;
        $prevSt = $pdo->prepare(
            'SELECT id, date FROM lessons
             WHERE class_id=? AND subject_id=? AND id<>?
               AND (date < ? OR (date = ? AND id < ?))
             ORDER BY date DESC, id DESC LIMIT 1'
        );
        $prevSt->execute([$lesson['class_id'], $lesson['subject_id'], $id, $lesson['date'], $lesson['date'], $id]);
        $prevLesson = $prevSt->fetch();
        if ($prevLesson) {
            $previousLessonDate = $prevLesson['date'];
            $hwSt = $pdo->prepare('SELECT * FROM homeworks WHERE lesson_id=? ORDER BY id LIMIT 1');
            $hwSt->execute([(int)$prevLesson['id']]);
            $previousHomework = $hwSt->fetch() ?: null;
        }

        $this->json([
            'lesson'     => $lesson,
            'students'   => $students,
            'marks'      => $marks,
            'remarks'    => $remarks,
            'attendance' => $attendance,
            'homeworks'  => $homeworks,
            'homework'           => $homework,
            'previous_homework'  => $previousHomework,
            'previous_lesson_date' => $previousLessonDate,
        ]);
    }

    public function lessonCreate(): void
    {
        $this->requireAdmin();
        $data = $this->input();
        $subjectId = (int)($data['subject_id'] ?? 0);
        $classId = (int)($data['class_id'] ?? 0);
        $date = trim((string)($data['date'] ?? ''));
        $startTime = trim((string)($data['start_time'] ?? ''));
        $topic = trim((string)($data['topic'] ?? ''));
        $lessonType = trim((string)($data['lesson_type'] ?? ''));
        $note = trim((string)($data['note'] ?? ''));

        if (!$subjectId || !$classId || $date === '') {
            $this->json(['error' => 'subject_id, class_id и date обязательны'], 400);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO lessons (subject_id, class_id, date, start_time, topic, lesson_type, note) VALUES (?,?,?,?,?,?,?)'
        )->execute([$subjectId, $classId, $date, $startTime ?: null, $topic ?: null, $lessonType ?: null, $note ?: null]);
        $id = (int)$pdo->lastInsertId();
        $this->json(['id' => $id], 201);
    }

    public function markSave(array $params): void
    {
        $this->requireAdmin();
        $lessonId = (int)$params[0];
        $data = $this->input();
        $marks = $data['marks'] ?? [];
        $pdo = Database::pdo();

        // Формат: marks[<student_id>] = [{value: 2..5, work_type: 'lesson'}, ...]
        // Список ученика полностью заменяет его оценки за урок
        // (пустой список удаляет все). Допускаются только оценки 2–5.
        foreach ($marks as $studentId => $entries) {
            $studentId = (int)$studentId;
            $pdo->prepare('DELETE FROM marks WHERE student_id=? AND lesson_id=?')
                ->execute([$studentId, $lessonId]);
            foreach ((array)$entries as $entry) {
                $entry = (array)$entry;
                $value = isset($entry['value']) ? (int)$entry['value'] : 0;
                $workType = trim((string)($entry['work_type'] ?? 'lesson'));
                if ($workType === '') {
                    $workType = 'lesson';
                }
                $comment = trim((string)($entry['comment'] ?? ''));
                if ($value < 2 || $value > 5) {
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO marks (student_id, lesson_id, value, work_type, comment) VALUES (?,?,?,?,?)'
                )->execute([$studentId, $lessonId, $value, $workType, $comment]);
            }
        }
        $this->json(['ok' => true]);
    }

    public function remarkSave(array $params): void
    {
        $this->requireAdmin();
        $lessonId = (int)$params[0];
        $data = $this->input();
        $remarks = $data['remarks'] ?? [];
        $pdo = Database::pdo();

        $pdo->prepare('DELETE FROM lesson_remarks WHERE lesson_id=?')
            ->execute([$lessonId]);

        foreach ($remarks as $studentId => $texts) {
            $studentId = (int)$studentId;
            foreach ((array)$texts as $text) {
                $text = trim((string)$text);
                if ($text !== '') {
                    $pdo->prepare('INSERT INTO lesson_remarks (lesson_id, student_id, text) VALUES (?,?,?)')
                        ->execute([$lessonId, $studentId, $text]);
                }
            }
        }
        $this->json(['ok' => true]);
    }

    public function attendanceSave(array $params): void
    {
        $this->requireAdmin();
        $lessonId = (int)$params[0];
        $data = $this->input();
        $records = $data['attendance'] ?? [];
        $pdo = Database::pdo();

        foreach ($records as $studentId => $entry) {
            $studentId = (int)$studentId;
            $entry = (array)$entry;
            $status = trim((string)($entry['status'] ?? 'present'));
            $comment = trim((string)($entry['comment'] ?? ''));
            $lateMinutes = isset($entry['late_minutes']) ? max(0, (int)$entry['late_minutes']) : null;
            if (!in_array($status, ['present', 'absent', 'late'], true)) continue;
            if ($status !== 'late') {
                $lateMinutes = null;
            }

            $pdo->prepare(
                'INSERT INTO attendance (student_id, lesson_id, status, comment, late_minutes) VALUES (?,?,?,?,?)
                 ON CONFLICT(student_id, lesson_id) DO UPDATE SET status=excluded.status, comment=excluded.comment, late_minutes=excluded.late_minutes'
            )->execute([$studentId, $lessonId, $status, $comment, $lateMinutes]);
        }
        $this->json(['ok' => true]);
    }

    /**
     * Домашнее задание урока (задаётся сейчас, выполняется к следующему уроку).
     * Тело: {title, description, due_date}. Обновляет существующее ДЗ урока
     * или создаёт его (одно ДЗ на урок).
     */
    public function homeworkSave(array $params): void
    {
        $this->requireAdmin();
        $lessonId = (int)$params[0];
        $data = $this->input();
        $title = trim((string)($data['title'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $dueDate = trim((string)($data['due_date'] ?? ''));
        $pdo = Database::pdo();

        $st = $pdo->prepare('SELECT id FROM homeworks WHERE lesson_id=? ORDER BY id LIMIT 1');
        $st->execute([$lessonId]);
        $existing = $st->fetch();

        if ($existing) {
            $pdo->prepare('UPDATE homeworks SET title=?, description=?, due_date=? WHERE id=? AND lesson_id=?')
                ->execute([$title ?: null, $description ?: null, $dueDate ?: null, (int)$existing['id'], $lessonId]);
        } else {
            $pdo->prepare('INSERT INTO homeworks (lesson_id, title, description, due_date) VALUES (?,?,?,?)')
                ->execute([$lessonId, $title ?: null, $description ?: null, $dueDate ?: null]);
        }
        $this->json(['ok' => true]);
    }

    /**
     * Удаление домашнего задания и связанных оценок (work_type = 'ДЗ').
     */
    public function homeworkDelete(array $params): void
    {
        $this->requireAdmin();
        $id = (int)$params[0];
        $pdo = Database::pdo();

        $st = $pdo->prepare('SELECT lesson_id FROM homeworks WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        $lessonId = (int)$row['lesson_id'];

        $pdo->prepare('DELETE FROM marks WHERE lesson_id=? AND work_type=?')->execute([$lessonId, 'ДЗ']);
        $pdo->prepare('DELETE FROM homeworks WHERE id=?')->execute([$id]);

        $this->json(['ok' => true]);
    }

    public function students(): void
    {
        $this->requireAdmin();
        $classId = (int)($_GET['class_id'] ?? 0);
        $pdo = Database::pdo();
        if ($classId) {
            $st = $pdo->prepare('SELECT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name');
            $st->execute([$classId]);
        } else {
            $st = $pdo->query('SELECT * FROM students WHERE is_active=1 ORDER BY last_name, first_name');
        }
        $this->json(['students' => $st->fetchAll()]);
    }

    public function quarters(): void
    {
        $this->requireAdmin();
        $rows = Database::pdo()->query('SELECT * FROM quarters ORDER BY start_date')->fetchAll();
        $this->json(['quarters' => $rows]);
    }

    /**
     * Журнал для класса: все уроки по предмету за текущую четверту + оценки + посещаемость.
     * GET /api/v1/class-journal?class_id=X&subject_id=Y
     */
    public function classJournal(): void
    {
        $this->requireAdmin();
        $classId = (int)($_GET['class_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        if (!$classId || !$subjectId) {
            $this->json(['error' => 'class_id и subject_id обязательны'], 400);
        }

        $pdo = Database::pdo();

        // Определяем четверту: по quarter_id или по текущей дате
        $quarterId = (int)($_GET['quarter_id'] ?? 0);
        $quarter = null;
        if ($quarterId) {
            $st = $pdo->prepare('SELECT * FROM quarters WHERE id=?');
            $st->execute([$quarterId]);
            $quarter = $st->fetch();
        } else {
            $today = date('Y-m-d');
            $st = $pdo->prepare('SELECT * FROM quarters WHERE start_date <= ? AND end_date >= ? ORDER BY id DESC LIMIT 1');
            $st->execute([$today, $today]);
            $quarter = $st->fetch();
        }

        // Уроки по классу+предмету в пределах четверти
        $sql = 'SELECT l.id, l.date, l.start_time, l.topic
                FROM lessons l
                WHERE l.class_id=? AND l.subject_id=?';
        $args = [$classId, $subjectId];
        if ($quarter) {
            $sql .= ' AND l.date >= ? AND l.date <= ?';
            $args[] = $quarter['start_date'];
            $args[] = $quarter['end_date'];
        }
        $sql .= ' ORDER BY l.date ASC, l.start_time ASC';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $lessons = $st->fetchAll();

        $lessonIds = array_column($lessons, 'id');
        $lessonIdInts = array_map('intval', $lessonIds);

        // Ученики класса
        $st = $pdo->prepare('SELECT s.id, s.last_name, s.first_name, s.middle_name
            FROM students s JOIN student_classes sc ON sc.student_id=s.id
            WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name');
        $st->execute([$classId]);
        $students = $st->fetchAll();

        // Оценки по урокам
        $marks = [];
        if ($lessonIdInts) {
            $placeholders = implode(',', array_fill(0, count($lessonIdInts), '?'));
            $st = $pdo->prepare("SELECT lesson_id, student_id, value, work_type, comment
                FROM marks WHERE lesson_id IN ($placeholders) ORDER BY id");
            $st->execute($lessonIdInts);
            foreach ($st->fetchAll() as $m) {
                $marks[(string)$m['lesson_id']][(string)$m['student_id']][] = [
                    'value' => (int)$m['value'],
                    'work_type' => $m['work_type'],
                    'comment' => $m['comment'] ?? '',
                ];
            }
        }

        // Посещаемость по урокам
        $attendance = [];
        if ($lessonIdInts) {
            $placeholders = implode(',', array_fill(0, count($lessonIdInts), '?'));
            $st = $pdo->prepare("SELECT lesson_id, student_id, status, late_minutes
                FROM attendance WHERE lesson_id IN ($placeholders)");
            $st->execute($lessonIdInts);
            foreach ($st->fetchAll() as $a) {
                $attendance[(string)$a['lesson_id']][(string)$a['student_id']] = [
                    'status' => $a['status'],
                    'late_minutes' => $a['late_minutes'] ?? 0,
                ];
            }
        }

        $this->json([
            'lessons'    => $lessons,
            'students'   => $students,
            'marks'      => $marks,
            'attendance' => $attendance,
        ]);
    }

    public function grades(): void
    {
        $this->requireAdmin();
        $classId = (int)($_GET['class_id'] ?? 0);
        $quarterId = (int)($_GET['quarter_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $pdo = Database::pdo();

        $quarter = null;
        if ($quarterId) {
            $st = $pdo->prepare('SELECT * FROM quarters WHERE id=?');
            $st->execute([$quarterId]);
            $quarter = $st->fetch();
        }

        $sql = 'SELECT s.id AS student_id, s.last_name, s.first_name, s.middle_name,
                       m.value, m.work_type, m.created_at, l.date AS lesson_date
                FROM students s
                JOIN marks m ON m.student_id = s.id
                JOIN lessons l ON l.id = m.lesson_id';
        $where = ['s.is_active=1'];
        $args = [];
        if ($classId) { $where[] = 's.class_id=?'; $args[] = $classId; }
        if ($subjectId) { $where[] = 'l.subject_id=?'; $args[] = $subjectId; }
        if ($quarter) {
            $where[] = 'l.date >= ?'; $args[] = $quarter['start_date'];
            $where[] = 'l.date <= ?'; $args[] = $quarter['end_date'];
        }
        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY s.last_name, s.first_name, l.date';

        $st = $pdo->prepare($sql);
        $st->execute($args);
        $this->json(['grades' => $st->fetchAll()]);
    }

    /**
     * Внутренний эндпоинт зеркала родителей: auth-web вызывает его
     * сервер-к-сервер после каждого изменения в разделе «Родители»,
     * чтобы зеркало в журнале обновлялось сразу, без ожидания TTL.
     * Только POST без Origin с доверенного IP (см. internal_ips в config).
     */
    public function parentsSync(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = config()['internal_ips'] ?? [];
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->json([]);
        }
        if ($origin !== '' || !in_array($ip, $trusted, true)) {
            error_log('[api] rejected parents-sync: origin=' . ($origin ?: '-') . ' ip=' . $ip);
            $this->json(['error' => 'Forbidden'], 403);
        }
        $report = ParentService::sync();
        if ($report['errors']) {
            $this->json(['error' => implode(' ', $report['errors']), 'report' => $report], 502);
        }
        $this->json(['ok' => true, 'report' => $report]);
    }
}
