<?php

class StudentController
{
    private function boot(): void
    {
        Auth::requireRole('student');
    }

    private function student(): ?array
    {
        $st = Database::pdo()->prepare('SELECT s.*, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.user_id=?');
        $st->execute([Auth::id()]);
        return $st->fetch() ?: null;
    }

    public function index(): string
    {
        $this->boot();
        $s = $this->student();
        if (!$s) {
            return View::render('my/empty', ['note' => 'Для вашей учётной записи не привязан студент. Обратитесь к администратору.']);
        }
        $sid = (int)$s['id'];
        $pdo = Database::pdo();
        $lastMarks = $pdo->prepare('SELECT m.value, m.work_type, m.comment, l.date, sub.name AS subject FROM marks m JOIN lessons l ON l.id=m.lesson_id JOIN subjects sub ON sub.id=l.subject_id WHERE m.student_id=? ORDER BY l.date DESC, l.id DESC');
        $lastMarks->execute([$sid]);
        $pendingHw = $pdo->prepare('SELECT hw.*, sub.name AS subject, l.date AS lesson_date,
                    (SELECT hws.status FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_status
                    FROM homeworks hw JOIN lessons l ON l.id=hw.lesson_id JOIN subjects sub ON sub.id=l.subject_id
                    WHERE l.class_id=? ORDER BY COALESCE(hw.due_date,l.date) DESC');
        $pendingHw->execute([$sid, $s['class_id']]);
        $remarks = $pdo->prepare('SELECT r.text, r.created_at, l.date, sub.name AS subject FROM lesson_remarks r JOIN lessons l ON l.id=r.lesson_id JOIN subjects sub ON sub.id=l.subject_id WHERE r.student_id=? ORDER BY r.created_at DESC');
        $remarks->execute([$sid]);
        return View::render('my/index', [
            'student' => $s,
            'lastMarks' => $lastMarks->fetchAll(),
            'pendingHw' => $pendingHw->fetchAll(),
            'remarks' => $remarks->fetchAll(),
            'progress' => StudentProgressService::mySummary(isset($_GET['refresh']) && $_GET['refresh'] === '1'),
        ]);
    }

    public function grades(): string
    {
        $this->boot();
        $s = $this->student();
        if (!$s) return View::render('my/empty', ['note' => 'Нет привязки к студенту.']);
        $bySubject = GradeService::perSubject((int)$s['id']);
        return View::render('my/grades', ['student' => $s, 'bySubject' => $bySubject]);
    }

    public function homeworks(): string
    {
        $this->boot();
        $s = $this->student();
        if (!$s) return View::render('my/empty', ['note' => 'Нет привязки к студенту.']);
        $sid = (int)$s['id'];
        $pdo = Database::pdo();
        $rows = $pdo->prepare('SELECT hw.*, sub.name AS subject, l.date AS lesson_date, l.topic,
                    (SELECT hws.status FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_status,
                    (SELECT hws.result_mark FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_mark,
                    (SELECT hws.comment FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_comment
                    FROM homeworks hw
                    JOIN lessons l ON l.id=hw.lesson_id
                    JOIN subjects sub ON sub.id=l.subject_id
                    WHERE l.class_id=?
                    ORDER BY COALESCE(hw.due_date,l.date) DESC');
        $rows->execute([$sid, $sid, $sid, $s['class_id']]);
        return View::render('my/homeworks', ['student' => $s, 'rows' => $rows->fetchAll()]);
    }

    public function homeworkSubmit(array $params): void
    {
        $this->boot();
        if (!verify_csrf()) {
            flash_set('my_error', 'Ошибка безопасности.');
            redirect('/my/homeworks');
        }
        $sid = $this->student();
        if (!$sid) redirect('/my/homeworks');
        $hwId = (int)$params[0];
        $status = (string)($_POST['status'] ?? 'done');
        $comment = trim((string)($_POST['comment'] ?? ''));
        $pdo = Database::pdo();
        if ($status === 'reset') {
            // убрать статус = удалить свою отметку о выполнении
            $pdo->prepare('DELETE FROM homework_submissions WHERE homework_id=? AND student_id=?')
                ->execute([$hwId, $sid['id']]);
            flash_set('my_ok', 'Статус убран.');
            redirect('/my/homeworks');
        }
        if (!in_array($status, ['done','partial','not_done'])) $status = 'done';
        $exists = $pdo->prepare('SELECT id FROM homework_submissions WHERE homework_id=? AND student_id=?');
        $exists->execute([$hwId, $sid['id']]);
        if ($exists->fetch()) {
            $pdo->prepare('UPDATE homework_submissions SET status=?, comment=?, submitted_at=? WHERE homework_id=? AND student_id=?')
                ->execute([$status, $comment ?: null, now(), $hwId, $sid['id']]);
        } else {
            $pdo->prepare('INSERT INTO homework_submissions (homework_id, student_id, status, comment, submitted_at) VALUES (?,?,?,?,?)')
                ->execute([$hwId, $sid['id'], $status, $comment ?: null, now()]);
        }
        flash_set('my_ok', 'Статус выполнения обновлён.');
        redirect('/my/homeworks');
    }

    public function remarks(): string
    {
        $this->boot();
        $s = $this->student();
        if (!$s) return View::render('my/empty', ['note' => 'Нет привязки к студенту.']);
        $st = Database::pdo()->prepare('SELECT r.text, r.created_at, l.date, sub.name AS subject FROM lesson_remarks r JOIN lessons l ON l.id=r.lesson_id JOIN subjects sub ON sub.id=l.subject_id WHERE r.student_id=? ORDER BY r.created_at DESC');
        $st->execute([$s['id']]);
        return View::render('my/remarks', ['student' => $s, 'rows' => $st->fetchAll()]);
    }
}