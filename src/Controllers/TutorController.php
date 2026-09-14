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
            'className' => $className,
        ]);
    }
}
