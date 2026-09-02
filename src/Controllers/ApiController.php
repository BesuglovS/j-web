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
            'SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.is_active=1) AS student_count
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
        $pdo = Database::pdo();
        $sql = 'SELECT l.*, c.name AS class_name, s.name AS subject_name
                FROM lessons l
                JOIN classes c ON c.id=l.class_id
                JOIN subjects s ON s.id=l.subject_id';
        $where = [];
        $args = [];
        if ($classId) { $where[] = 'l.class_id=?'; $args[] = $classId; }
        if ($subjectId) { $where[] = 'l.subject_id=?'; $args[] = $subjectId; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY l.date DESC, l.start_time DESC';
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

        $students = $pdo->prepare('SELECT * FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name, first_name');
        $students->execute([$lesson['class_id']]);
        $students = $students->fetchAll();

        $marks = [];
        $st = $pdo->prepare('SELECT * FROM marks WHERE lesson_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $m) {
            $marks[$m['student_id']][$m['work_type']] = $m;
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

        $this->json([
            'lesson'     => $lesson,
            'students'   => $students,
            'marks'      => $marks,
            'remarks'    => $remarks,
            'attendance' => $attendance,
            'homeworks'  => $homeworks,
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

        foreach ($marks as $studentId => $byType) {
            $studentId = (int)$studentId;
            foreach ($byType as $workType => $entry) {
                $workType = trim((string)$workType);
                $value = isset($entry['value']) ? (int)$entry['value'] : 0;
                $comment = trim((string)($entry['comment'] ?? ''));
                if ($workType === '') continue;

                if ($value === 0) {
                    $pdo->prepare('DELETE FROM marks WHERE student_id=? AND lesson_id=? AND work_type=?')
                        ->execute([$studentId, $lessonId, $workType]);
                } elseif ($value >= 1 && $value <= 5) {
                    $pdo->prepare(
                        'INSERT INTO marks (student_id, lesson_id, value, work_type, comment) VALUES (?,?,?,?,?)
                         ON CONFLICT(student_id, lesson_id, work_type) DO UPDATE SET value=excluded.value, comment=excluded.comment'
                    )->execute([$studentId, $lessonId, $value, $workType, $comment]);
                }
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
        $removeIds = $data['remove_ids'] ?? [];
        $pdo = Database::pdo();

        foreach ($removeIds as $remarkId) {
            $pdo->prepare('DELETE FROM lesson_remarks WHERE id=? AND lesson_id=?')
                ->execute([(int)$remarkId, $lessonId]);
        }

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
            $status = trim((string)($entry['status'] ?? 'present'));
            $comment = trim((string)($entry['comment'] ?? ''));
            if (!in_array($status, ['present', 'absent', 'late'], true)) continue;

            $pdo->prepare(
                'INSERT INTO attendance (student_id, lesson_id, status, comment) VALUES (?,?,?,?)
                 ON CONFLICT(student_id, lesson_id) DO UPDATE SET status=excluded.status, comment=excluded.comment'
            )->execute([$studentId, $lessonId, $status, $comment]);
        }
        $this->json(['ok' => true]);
    }

    public function students(): void
    {
        $this->requireAdmin();
        $classId = (int)($_GET['class_id'] ?? 0);
        $pdo = Database::pdo();
        if ($classId) {
            $st = $pdo->prepare('SELECT * FROM students WHERE class_id=? AND is_active=1 ORDER BY last_name, first_name');
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
}
