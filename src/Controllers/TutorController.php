<?php

/**
 * Кабинет тьютора (классного руководителя): read-only просмотр
 * успеваемости учеников своих классов. Роль резолвится в Auth::user()
 * по таблице tutors (логин совпадает с SSO-логином auth-web).
 * Никаких POST-операций — журнал ведёт только администратор.
 */
class TutorController
{
    private function boot(): void
    {
        Auth::requireRole('tutor');
        // классы и студенты — read-only зеркала auth-web, подтягиваем перед показом
        GroupService::ensureSynced();
        StudentService::ensureSynced();
    }

    /** Классы тьютора с числом активных студентов */
    private function myClasses(): array
    {
        $st = Database::pdo()->prepare(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM student_classes sc JOIN students s ON s.id=sc.student_id
                     WHERE sc.class_id=c.id AND s.is_active=1) AS cnt
             FROM tutor_classes tc
             JOIN classes c ON c.id=tc.class_id
             WHERE tc.tutor_id=?
             ORDER BY c.grade, c.name'
        );
        $st->execute([Auth::id()]);
        return $st->fetchAll();
    }

    public function index(): string
    {
        $this->boot();
        return View::render('tutor/index', [
            'title'   => 'Мои классы',
            'classes' => $this->myClasses(),
        ]);
    }

    public function grades(): string
    {
        $this->boot();
        $classId = (int)($_GET['class_id'] ?? 0);
        $quarterId = (int)($_GET['quarter_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $classes = $this->myClasses();

        // доступ только к своим классам (чужой class_id — 403)
        if ($classId) {
            $own = false;
            foreach ($classes as $c) {
                if ((int)$c['id'] === $classId) {
                    $own = true;
                    break;
                }
            }
            if (!$own) {
                http_response_code(403);
                exit('Доступ запрещён.');
            }
            $st = Database::pdo()->prepare('SELECT * FROM subjects WHERE class_id=? ORDER BY name');
            $st->execute([$classId]);
            $subjects = $st->fetchAll();
        } else {
            $subjects = [];
        }

        $quarters = Database::pdo()->query('SELECT * FROM quarters ORDER BY start_date')->fetchAll();
        $grades = $classId
            ? GradeService::report($classId, $quarterId, $subjectId)
            : ['students' => [], 'subjects' => [], 'markSets' => []];

        // список домашних заданий класса (с учётом фильтров периода и предмета)
        $homeworks = [];
        if ($classId) {
            $from = null; $to = null;
            if ($quarterId) {
                $q = Database::pdo()->prepare('SELECT start_date, end_date FROM quarters WHERE id=?');
                $q->execute([$quarterId]);
                if ($qq = $q->fetch()) { $from = $qq['start_date']; $to = $qq['end_date']; }
            }
            $sql = "SELECT hw.id, hw.title, hw.description, hw.due_date, hw.lesson_id,
                           l.date AS lesson_date, sub.name AS subject,
                           (SELECT COUNT(*) FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.status='done') AS cnt_done,
                           (SELECT COUNT(*) FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.status='partial') AS cnt_partial,
                           (SELECT COUNT(*) FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.status='not_done') AS cnt_not,
                           (SELECT COUNT(*) FROM students s JOIN student_classes sc ON sc.student_id=s.id WHERE sc.class_id=? AND s.is_active=1) AS students_cnt
                    FROM homeworks hw
                    JOIN lessons l ON l.id=hw.lesson_id
                    JOIN subjects sub ON sub.id=l.subject_id
                    WHERE l.class_id=?";
            $ar = [$classId, $classId];
            if ($subjectId) { $sql .= ' AND l.subject_id=?'; $ar[] = $subjectId; }
            if ($from) { $sql .= ' AND l.date >= ?'; $ar[] = $from; }
            if ($to)   { $sql .= ' AND l.date <= ?'; $ar[] = $to; }
            $sql .= ' ORDER BY COALESCE(hw.due_date,l.date) DESC';
            $st = Database::pdo()->prepare($sql);
            $st->execute($ar);
            $homeworks = $st->fetchAll();
        }

        $className = '';
        foreach ($classes as $c) {
            if ((int)$c['id'] === $classId) {
                $className = (string)$c['name'];
                break;
            }
        }

        return View::render('tutor/grades', [
            'title'     => 'Оценки',
            'classId'   => $classId,
            'quarterId' => $quarterId,
            'subjectId' => $subjectId,
            'classes'   => $classes,
            'quarters'  => $quarters,
            'subjects'  => $subjects,
            'grades'    => $grades,
            'homeworks' => $homeworks,
            'className' => $className,
        ]);
    }

    /**
     * Детальный просмотр класса: для выбранного ученика — все оценки
     * (по предметам со средней), домашние задания со статусами и замечания.
     */
    public function classDetail(array $params): string
    {
        $this->boot();
        $classId = (int)($params[0] ?? 0);

        // доступ только к своим классам (чужой class_id — 403)
        $className = '';
        foreach ($this->myClasses() as $c) {
            if ((int)$c['id'] === $classId) {
                $className = (string)$c['name'];
                break;
            }
        }
        if ($className === '') {
            http_response_code(403);
            exit('Доступ запрещён.');
        }

        // активные студенты класса (числятся в классе или его подгруппах)
        $st = Database::pdo()->prepare(
            'SELECT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id
             WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name'
        );
        $st->execute([$classId]);
        $students = $st->fetchAll();

        $studentId = (int)($_GET['student_id'] ?? 0);
        $student = null;
        foreach ($students as $s) {
            if ((int)$s['id'] === $studentId) {
                $student = $s;
                break;
            }
        }
        // по умолчанию показываем первого ученика списка
        if (!$student && $students) {
            $student = $students[0];
        }

        $bySubject = [];
        $homeworks = [];
        $remarks = [];

        if ($student) {
            $sid = (int)$student['id'];
            $pdo = Database::pdo();
            // все группы ученика (класс + подгруппы)
            $st = $pdo->prepare('SELECT class_id FROM student_classes WHERE student_id=?');
            $st->execute([$sid]);
            $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$classIds && $student['class_id']) $classIds = [(int)$student['class_id']];
            if (!$classIds) $classIds = [0];
            $hwFilter = implode(',', array_fill(0, count($classIds), '?'));

            $st = $pdo->prepare('SELECT c.name FROM student_classes sc JOIN classes c ON c.id=sc.class_id WHERE sc.student_id=? ORDER BY c.name');
            $st->execute([$sid]);
            $names = $st->fetchAll(PDO::FETCH_COLUMN);
            $student['all_class_names'] = implode(', ', $names) ?: (string)($student['class_name'] ?? '');

            $bySubject = GradeService::perSubject($sid);

            $hw = $pdo->prepare("SELECT hw.*, sub.name AS subject, l.date AS lesson_date, c.name AS class_name,
                    (SELECT hws.status FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_status,
                    (SELECT hws.result_mark FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_mark,
                    (SELECT hws.comment FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_comment
                    FROM homeworks hw
                    JOIN lessons l ON l.id=hw.lesson_id
                    JOIN subjects sub ON sub.id=l.subject_id
                    JOIN classes c ON c.id=l.class_id
                    WHERE l.class_id IN ($hwFilter)
                    ORDER BY COALESCE(hw.due_date,l.date) DESC");
            $hw->execute(array_merge([$sid, $sid, $sid], $classIds));
            $homeworks = $hw->fetchAll();

            $rem = $pdo->prepare('SELECT r.text, r.created_at, l.date, sub.name AS subject, c.name AS class_name FROM lesson_remarks r JOIN lessons l ON l.id=r.lesson_id JOIN subjects sub ON sub.id=l.subject_id JOIN classes c ON c.id=l.class_id WHERE r.student_id=? ORDER BY r.created_at DESC');
            $rem->execute([$sid]);
            $remarks = $rem->fetchAll();
        }

        return View::render('tutor/class', [
            'title'     => 'Класс ' . $className,
            'classId'   => $classId,
            'className' => $className,
            'students'  => $students,
            'student'   => $student,
            'bySubject' => $bySubject,
            'homeworks' => $homeworks,
            'remarks'   => $remarks,
        ]);
    }
}
