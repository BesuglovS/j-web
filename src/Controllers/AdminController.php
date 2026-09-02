<?php

class AdminController
{
    private function boot(): void
    {
        Auth::requireRole('admin');
    }

    private function csrfGuard(): void
    {
        if (!verify_csrf()) {
            flash_set('admin_error', 'Ошибка безопасности формы. Повторите действие.');
            redirect($_POST['back'] ?? '/admin');
        }
    }

    // ================== Обзор ==================
    public function index(): string
    {
        $this->boot();
        $pdo = Database::pdo();
        $stats = [
            'classes'  => (int)$pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn(),
            'subjects' => (int)$pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn(),
            'students' => (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn(),
            'parents'  => (int)$pdo->query('SELECT COUNT(*) FROM parents')->fetchColumn(),
            'lessons'  => (int)$pdo->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
        ];
        return View::render('admin/index', compact('stats'));
    }

    // ================== Классы ==================
    public function classes(): string
    {
        $this->boot();
        GroupService::ensureSynced();
        $rows = Database::pdo()->query('SELECT c.*, (SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.is_active=1) AS cnt FROM classes c ORDER BY c.grade, c.name')->fetchAll();
        return View::render('admin/classes', compact('rows'));
    }

    public function classSync(): void
    {
        $this->boot();
        $this->csrfGuard();
        $report = GroupService::sync();
        if ($report['errors']) {
            flash_set('admin_error', implode(' ', $report['errors']));
        } else {
            flash_set('admin_ok', 'Классы обновлены: добавлено ' . $report['added'] . ', обновлено ' . $report['updated'] . '.');
        }
        redirect('/admin/classes');
    }

    // ================== Предметы ==================
    public function subjects(): string
    {
        $this->boot();
        GroupService::ensureSynced();
        $rows = Database::pdo()->query(
            'SELECT s.*, c.name AS class_name
             FROM subjects s
             JOIN classes c ON c.id = s.class_id
             ORDER BY c.grade, c.name, s.name'
        )->fetchAll();
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY grade, name')->fetchAll();
        return View::render('admin/subjects', compact('rows', 'classes'));
    }

    public function subjectSave(): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)($_POST['id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $short = trim((string)($_POST['short_name'] ?? ''));
        $pdo = Database::pdo();
        if (!$classId) {
            flash_set('admin_error', 'Укажите класс для предмета.');
            redirect('/admin/subjects');
        }
        if ($id) {
            $pdo->prepare('UPDATE subjects SET class_id=?, name=?, short_name=? WHERE id=?')->execute([$classId, $name, $short, $id]);
        } else {
            $pdo->prepare('INSERT INTO subjects (class_id, name, short_name) VALUES (?,?,?)')->execute([$classId, $name, $short]);
        }
        flash_set('admin_ok', 'Предмет сохранён.');
        redirect('/admin/subjects');
    }

    public function subjectDelete(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        Database::pdo()->prepare('DELETE FROM subjects WHERE id=?')->execute([(int)$params[0]]);
        flash_set('admin_ok', 'Предмет удалён.');
        redirect('/admin/subjects');
    }

    // ================== Четверти ==================
    public function quartersIndex(): string
    {
        $this->boot();
        $rows = Database::pdo()->query('SELECT * FROM quarters ORDER BY start_date')->fetchAll();
        return View::render('admin/quarters', compact('rows'));
    }

    public function quarterSave(): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $start = (string)($_POST['start_date'] ?? '');
        $end = (string)($_POST['end_date'] ?? '');
        $pdo = Database::pdo();
        if ($id) {
            $pdo->prepare('UPDATE quarters SET name=?, start_date=?, end_date=? WHERE id=?')->execute([$name, $start, $end, $id]);
        } else {
            $pdo->prepare('INSERT INTO quarters (name, start_date, end_date) VALUES (?,?,?)')->execute([$name, $start, $end]);
        }
        flash_set('admin_ok', 'Период сохранён.');
        redirect('/admin/quarters');
    }

    public function quarterDelete(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        Database::pdo()->prepare('DELETE FROM quarters WHERE id=?')->execute([(int)$params[0]]);
        flash_set('admin_ok', 'Период удалён.');
        redirect('/admin/quarters');
    }

    // ================== Студенты (только просмотр) ==================
    public function studentsIndex(): string
    {
        $this->boot();
        StudentService::ensureSynced();
        $classId = (int)($_GET['class_id'] ?? 0);
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY grade, name')->fetchAll();
        $rows = [];
        if ($classId) {
            $st = Database::pdo()->prepare(
                'SELECT s.*, c.name AS class_name, u.login AS login, u.id AS user_id
                  FROM students s
                  LEFT JOIN classes c ON c.id = s.class_id
                  LEFT JOIN users u ON u.id = s.user_id
                  WHERE s.class_id = ? AND s.is_active = 1
                  ORDER BY s.last_name, s.first_name'
            );
            $st->execute([$classId]);
            $rows = $st->fetchAll();
        }
        return View::render('admin/students', compact('rows', 'classes', 'classId'));
    }

    public function studentSync(): void
    {
        $this->boot();
        $this->csrfGuard();
        $report = StudentService::sync();
        if ($report['errors']) {
            flash_set('admin_error', implode(' ', $report['errors']));
        } else {
            flash_set('admin_ok', 'Студенты обновлены: добавлено ' . $report['added'] . ', обновлено ' . $report['updated'] . ', удалено ' . $report['removed'] . ', скрыто (есть данные) ' . $report['deactivated'] . '.');
        }
        redirect('/admin/students');
    }

    public function studentView(array $params): string
    {
        $this->boot();
        $id = (int)$params[0];
        $pdo = Database::pdo();
        $student = $pdo->prepare('SELECT s.*, c.name AS class_name, u.login FROM students s LEFT JOIN classes c ON c.id=s.class_id LEFT JOIN users u ON u.id=s.user_id WHERE s.id=?');
        $student->execute([$id]);
        $student = $student->fetch();
        if (!$student) {
            throw new RuntimeException('Студент не найден');
        }
        $parents = $pdo->prepare('SELECT p.* FROM parents p JOIN student_parent sp ON sp.parent_id=p.id WHERE sp.student_id=?');
        $parents->execute([$id]);
        $grades = $pdo->prepare(
            'SELECT sub.name AS subject, l.date, m.value, m.work_type, m.comment
             FROM marks m
             JOIN lessons l ON l.id=m.lesson_id
             JOIN subjects sub ON sub.id=l.subject_id
             WHERE m.student_id=? ORDER BY l.date DESC, l.id DESC'
        );
        $grades->execute([$id]);
        $remarks = $pdo->prepare(
            'SELECT l.date, sub.name AS subject, r.text, r.created_at
             FROM lesson_remarks r
             JOIN lessons l ON l.id=r.lesson_id
             JOIN subjects sub ON sub.id=l.subject_id
             WHERE r.student_id=? ORDER BY r.created_at DESC'
        );
        $remarks->execute([$id]);
        $allParents = $pdo->query('SELECT * FROM parents ORDER BY last_name, first_name')->fetchAll();

        $allGrades = $pdo->prepare(
            'SELECT sub.name AS subject, ROUND(AVG(m.value),2) AS avg, COUNT(*) AS cnt
             FROM marks m JOIN lessons l ON l.id=m.lesson_id JOIN subjects sub ON sub.id=l.subject_id
             WHERE m.student_id=? GROUP BY sub.id ORDER BY sub.name'
        );
        $allGrades->execute([$id]);

        return View::render('admin/studentView', compact('student', 'parents', 'grades', 'remarks', 'allParents', 'allGrades'));
    }

    // ================== Родители ==================
    public function parentsIndex(): string
    {
        $this->boot();
        $classId = (int)($_GET['class_id'] ?? 0);
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY grade, name')->fetchAll();
        $rows = [];
        if ($classId) {
            $st = Database::pdo()->prepare(
                'SELECT p.*, u.login AS login, u.id AS user_id,
                        (SELECT GROUP_CONCAT(c.name, ", ") FROM student_parent sp JOIN students s ON s.id=sp.student_id JOIN classes c ON c.id=s.class_id WHERE sp.parent_id=p.id) AS children
                 FROM parents p
                 LEFT JOIN users u ON u.id = p.user_id
                 WHERE EXISTS (
                     SELECT 1 FROM student_parent sp2
                     JOIN students s2 ON s2.id = sp2.student_id
                     WHERE sp2.parent_id = p.id AND s2.class_id = ?
                 )
                 ORDER BY p.last_name, p.first_name'
            );
            $st->execute([$classId]);
            $rows = $st->fetchAll();
        }
        return View::render('admin/parents', compact('rows', 'classes', 'classId'));
    }

    public function parentForm(): string
    {
        $this->boot();
        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        if ($id) {
            $row = Database::pdo()->prepare('SELECT * FROM parents WHERE id=?');
            $row->execute([$id]);
            $row = $row->fetch();
        }
        return View::render('admin/parentForm', compact('row'));
    }

    public function parentSave(): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)($_POST['id'] ?? 0);
        $data = array_map('trim', [
            'last_name'   => (string)($_POST['last_name'] ?? ''),
            'first_name'  => (string)($_POST['first_name'] ?? ''),
            'middle_name' => (string)($_POST['middle_name'] ?? ''),
        ]);
        $newLogin = trim((string)($_POST['new_login'] ?? ''));
        $newPass = (string)($_POST['new_password'] ?? '');
        $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $pdo = Database::pdo();

        if ($newLogin !== '') {
            $user_id = $this->ensureUser($pdo, $newLogin, $newPass, 'parent', $this->full_name($data));
        }

        if ($id) {
            $pdo->prepare('UPDATE parents SET user_id=?, last_name=?, first_name=?, middle_name=? WHERE id=?')
                ->execute([$user_id, $data['last_name'], $data['first_name'], $data['middle_name'], $id]);
        } else {
            $pdo->prepare('INSERT INTO parents (user_id, last_name, first_name, middle_name) VALUES (?,?,?,?)')
                ->execute([$user_id, $data['last_name'], $data['first_name'], $data['middle_name']]);
        }
        flash_set('admin_ok', 'Родитель сохранён.');
        redirect('/admin/parents');
    }

    public function parentDelete(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        Database::pdo()->prepare('DELETE FROM parents WHERE id=?')->execute([(int)$params[0]]);
        flash_set('admin_ok', 'Родитель удалён.');
        redirect('/admin/parents');
    }

    public function linkSave(): void
    {
        $this->boot();
        $this->csrfGuard();
        $studentId = (int)($_POST['student_id'] ?? 0);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        $detach = isset($_POST['detach']) ? (int)$_POST['detach'] : null;
        $pdo = Database::pdo();
        if ($detach) {
            $pdo->prepare('DELETE FROM student_parent WHERE student_id=? AND parent_id=?')->execute([$studentId, $detach]);
        } else {
            $pdo->prepare('INSERT OR IGNORE INTO student_parent (student_id, parent_id) VALUES (?,?)')->execute([$studentId, $parentId]);
        }
        flash_set('admin_ok', 'Связь обновлена.');
        redirect('/admin/students/' . $studentId);
    }

    private function full_name(array $d): string
    {
        return trim(implode(' ', array_filter([$d['last_name'] ?? '', $d['first_name'] ?? '', $d['middle_name'] ?? ''])));
    }

    private function ensureUser(\PDO $pdo, string $login, string $pass, string $role, string $name): int
    {
        // проверим занятость
        $st = $pdo->prepare('SELECT id FROM users WHERE login=?');
        $st->execute([$login]);
        if ($u = $st->fetch()) {
            return (int)$u['id'];
        }
        $hash = password_hash($pass ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (login, password_hash, role, full_name) VALUES (?,?,?,?)')
            ->execute([$login, $hash, $role, $name]);
        return (int)$pdo->lastInsertId();
    }

    // ================== Журнал занятий ==================
    public function lessonsIndex(): string
    {
        $this->boot();
        GroupService::ensureSynced();
        StudentService::ensureSynced();
        $classId = (int)($_GET['class_id'] ?? 0);
        $rows = Database::pdo()->query(
            'SELECT l.*, c.name AS class_name, s.name AS subject_name, s.short_name
             FROM lessons l
             JOIN classes c ON c.id=l.class_id
             JOIN subjects s ON s.id=l.subject_id
             ORDER BY l.date DESC, l.id DESC'
        )->fetchAll();
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY grade,name')->fetchAll();
        return View::render('admin/lessons', compact('rows', 'classes', 'classId'));
    }

    public function lessonForm(): string
    {
        $this->boot();
        GroupService::ensureSynced();
        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        if ($id) {
            $row = Database::pdo()->prepare('SELECT * FROM lessons WHERE id=?');
            $row->execute([$id]);
            $row = $row->fetch();
        }
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY name')->fetchAll();
        $subjects = Database::pdo()->query('SELECT s.*, c.name AS class_name FROM subjects s JOIN classes c ON c.id=s.class_id ORDER BY s.name')->fetchAll();
        return View::render('admin/lessonForm', compact('row', 'classes', 'subjects'));
    }

    public function lessonSave(): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)($_POST['id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $date = (string)($_POST['date'] ?? '');
        $start = (string)($_POST['start_time'] ?? '');
        $topic = trim((string)($_POST['topic'] ?? ''));
        $type = trim((string)($_POST['lesson_type'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM subjects WHERE id=? AND class_id=?');
        $st->execute([$subjectId, $classId]);
        if (!$st->fetch()) {
            flash_set('admin_error', 'Выбранный предмет не относится к выбранному классу.');
            redirect('/admin/lessons');
        }
        if ($id) {
            $pdo->prepare('UPDATE lessons SET subject_id=?, class_id=?, date=?, start_time=?, topic=?, lesson_type=?, note=? WHERE id=?')
                ->execute([$subjectId, $classId, $date, $start ?: null, $topic ?: null, $type ?: null, $note ?: null, $id]);
            $lessonId = $id;
        } else {
            $pdo->prepare('INSERT INTO lessons (subject_id, class_id, date, start_time, topic, lesson_type, note) VALUES (?,?,?,?,?,?,?)')
                ->execute([$subjectId, $classId, $date, $start ?: null, $topic ?: null, $type ?: null, $note ?: null]);
            $lessonId = (int)$pdo->lastInsertId();
        }
        flash_set('admin_ok', 'Занятие сохранено.');
        redirect('/admin/lessons/' . $lessonId);
    }

    public function lessonDelete(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        Database::pdo()->prepare('DELETE FROM lessons WHERE id=?')->execute([(int)$params[0]]);
        flash_set('admin_ok', 'Занятие удалено.');
        redirect('/admin/lessons');
    }

    public function lessonView(array $params): string
    {
        $this->boot();
        StudentService::ensureSynced();
        $id = (int)$params[0];
        $pdo = Database::pdo();
        $lesson = $pdo->prepare('SELECT l.*, c.name AS class_name, s.name AS subject_name FROM lessons l JOIN classes c ON c.id=l.class_id JOIN subjects s ON s.id=l.subject_id WHERE l.id=?');
        $lesson->execute([$id]);
        $lesson = $lesson->fetch();
        if (!$lesson) {
            throw new RuntimeException('Занятие не найдено');
        }
        // студенты класса + их оценки/ДЗ/замечания за это занятие
        $students = $pdo->prepare('SELECT s.* FROM students s WHERE s.class_id=? AND s.is_active=1 ORDER BY s.last_name');
        $students->execute([$lesson['class_id']]);
        $students = $students->fetchAll();

        $marksMap = [];
        $st = $pdo->prepare('SELECT * FROM marks WHERE lesson_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $m) {
            $marksMap[$m['student_id'] . '|' . $m['work_type']] = $m;
        }

        $remarksMap = [];
        $st = $pdo->prepare('SELECT * FROM lesson_remarks WHERE lesson_id=?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $remarksMap[$r['student_id']][] = $r;
        }

        $homeworks = $pdo->prepare('SELECT * FROM homeworks WHERE lesson_id=?');
        $homeworks->execute([$id]);
        $homeworks = $homeworks->fetchAll();

        $submissionMap = [];
        foreach ($homeworks as $hw) {
            $st = $pdo->prepare('SELECT * FROM homework_submissions WHERE homework_id=?');
            $st->execute([$hw['id']]);
            foreach ($st->fetchAll() as $sub) {
                $submissionMap[$hw['id']][$sub['student_id']] = $sub;
            }
        }

        return View::render('admin/lessonView', compact('lesson', 'students', 'marksMap', 'remarksMap', 'homeworks', 'submissionMap'));
    }

    public function markSave(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        $lessonId = (int)$params[0];
        $pdo = Database::pdo();
        // Формат: marks[studentId][workType]=value, comments[studentId][workType]=comment
        $marks = (array)($_POST['marks'] ?? []);
        $comments = (array)($_POST['comments'] ?? []);
        foreach ($marks as $studentId => $byType) {
            $studentId = (int)$studentId;
            foreach ($byType as $workType => $v) {
                $workType = trim((string)$workType);
                $v = trim((string)$v);
                $comment = trim((string)($comments[$studentId][$workType] ?? ''));
                if ($workType === '') {
                    continue;
                }
                if ($v === '') {
                    $pdo->prepare('DELETE FROM marks WHERE student_id=? AND lesson_id=? AND work_type=?')->execute([$studentId, $lessonId, $workType]);
                } elseif (is_numeric($v) && (int)$v >= 1 && (int)$v <= 5) {
                    $pdo->prepare('INSERT INTO marks (student_id, lesson_id, value, work_type, comment) VALUES (?,?,?,?,?)
                        ON CONFLICT(student_id, lesson_id, work_type) DO UPDATE SET value=excluded.value, comment=excluded.comment')
                        ->execute([$studentId, $lessonId, (int)$v, $workType, $comment]);
                }
            }
        }
        flash_set('admin_ok', 'Оценки сохранены.');
        redirect('/admin/lessons/' . $lessonId);
    }

    public function remarkSave(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        $lessonId = (int)$params[0];
        $pdo = Database::pdo();
        // замечания: remarks[studentId][]=text, либо remove_remark[student_id]=id
        if (isset($_POST['remove_remark'])) {
            foreach ((array)$_POST['remove_remark'] as $sid => $rid) {
                $pdo->prepare('DELETE FROM lesson_remarks WHERE id=? AND lesson_id=?')->execute([(int)$rid, $lessonId]);
            }
        }
        if (isset($_POST['remarks'])) {
            foreach ((array)$_POST['remarks'] as $sid => $rowsArr) {
                $studentId = (int)$sid;
                // rowsArr может быть строкой (один инпут) — обернём
                foreach ((array)$rowsArr as $text) {
                    $text = trim((string)$text);
                    if ($text !== '') {
                        $pdo->prepare('INSERT INTO lesson_remarks (lesson_id, student_id, text) VALUES (?,?,?)')->execute([$lessonId, $studentId, $text]);
                    }
                }
            }
        }
        flash_set('admin_ok', 'Замечания сохранены.');
        redirect('/admin/lessons/' . $lessonId);
    }

    public function homeworkSave(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        $lessonId = (int)$params[0];
        $pdo = Database::pdo();
        $title = trim((string)$_POST['title'] ?? '');
        $desc = trim((string)$_POST['description'] ?? '');
        $due = (string)($_POST['due_date'] ?? '');
        $hwId = (int)($_POST['homework_id'] ?? 0);
        if ($hwId) {
            $pdo->prepare('UPDATE homeworks SET title=?, description=?, due_date=? WHERE id=? AND lesson_id=?')->execute([$title ?: null, $desc ?: null, $due ?: null, $hwId, $lessonId]);
        } else {
            $pdo->prepare('INSERT INTO homeworks (lesson_id, title, description, due_date) VALUES (?,?,?,?)')->execute([$lessonId, $title ?: null, $desc ?: null, $due ?: null]);
        }
        flash_set('admin_ok', 'Домашнее задание сохранено.');
        redirect('/admin/lessons/' . $lessonId);
    }

    public function homeworkDelete(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)$params[0];
        $pdo = Database::pdo();
        $lesson = $pdo->prepare('SELECT lesson_id FROM homeworks WHERE id=?');
        $lesson->execute([$id]);
        $lessonId = (int)$lesson->fetchColumn();
        $pdo->prepare('DELETE FROM homeworks WHERE id=?')->execute([$id]);
        flash_set('admin_ok', 'Задание удалено.');
        redirect('/admin/lessons/' . $lessonId);
    }

    public function submissionSave(array $params): void
    {
        $this->boot();
        $this->csrfGuard();
        $lessonId = (int)$params[0];
        $pdo = Database::pdo();
        $hwId = (int)($_POST['homework_id'] ?? 0);
        $submitRow = (array)($_POST['submissions'] ?? []);
        foreach ($submitRow as $studentId => $dataArr) {
            $studentId = (int)$studentId;
            $status = (string)($dataArr['status'] ?? 'not_done');
            $mark = trim((string)($dataArr['result_mark'] ?? ''));
            $comment = trim((string)($dataArr['comment'] ?? ''));
            $markInt = $mark !== '' && is_numeric($mark) ? (int)$mark : null;
            $pdo->prepare('INSERT INTO homework_submissions (homework_id, student_id, status, result_mark, comment, submitted_at) VALUES (?,?,?,?,?,?)
                ON CONFLICT(homework_id, student_id) DO UPDATE SET status=excluded.status, result_mark=excluded.result_mark, comment=excluded.comment, submitted_at=excluded.submitted_at')
                ->execute([$hwId, $studentId, in_array($status, ['done','partial','not_done']) ? $status : 'not_done', $markInt, $comment ?: null, now()]);
        }
        flash_set('admin_ok', 'Результаты выполнения обновлены.');
        redirect('/admin/lessons/' . $lessonId);
    }

    // ================== Оценки / сводка ==================
    public function gradesIndex(): string
    {
        $this->boot();
        GroupService::ensureSynced();
        StudentService::ensureSynced();
        $classId = (int)($_GET['class_id'] ?? 0);
        $quarterId = (int)($_GET['quarter_id'] ?? 0);
        $subjectId = (int)($_GET['subject_id'] ?? 0);
        $classes = Database::pdo()->query('SELECT * FROM classes ORDER BY name')->fetchAll();
        $quarters = Database::pdo()->query('SELECT * FROM quarters ORDER BY start_date')->fetchAll();
        if ($classId) {
            $st = Database::pdo()->prepare('SELECT * FROM subjects WHERE class_id=? ORDER BY name');
            $st->execute([$classId]);
            $subjects = $st->fetchAll();
        } else {
            $subjects = Database::pdo()->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
        }
        $grades = GradeService::report($classId, $quarterId, $subjectId);
        return View::render('admin/grades', compact('classId', 'quarterId', 'subjectId', 'classes', 'quarters', 'subjects', 'grades'));
    }

    // ================= Импорт =================
    public function importIndex(): string
    {
        $this->boot();
        return View::render('admin/import', [
            'templates' => ImportService::templates(),
        ]);
    }

    public function importLogs(): string
    {
        $this->boot();
        $rows = Database::pdo()->query('SELECT * FROM import_logs ORDER BY created_at DESC LIMIT 50')->fetchAll();
        return View::render('admin/importLogs', compact('rows'));
    }

    public function importProcess(): void
    {
        $this->boot();
        $this->csrfGuard();
        $kind = (string)($_POST['kind'] ?? '');
        if (empty($_FILES['csv_file']['tmp_name'])) {
            flash_set('admin_error', 'Не выбран файл.');
            redirect('/admin/import');
        }
        $result = ImportService::process($kind, $_FILES['csv_file']);
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO import_logs (filename, kind, rows_ok, rows_fail, detail) VALUES (?,?,?,?,?)')
            ->execute([$_FILES['csv_file']['name'], $kind, $result['ok'], $result['fail'], implode("\n", $result['errors'])]);
        flash_set('admin_import', ('Успешно: ' . $result['ok'] . ', ошибок: ' . $result['fail']));
        redirect('/admin/import');
    }

    // ================= Пользователи =================
    public function usersIndex(): string
    {
        $this->boot();
        $rows = Database::pdo()->query('SELECT * FROM users ORDER BY role, login')->fetchAll();
        return View::render('admin/users', compact('rows'));
    }

    public function userResetPassword(): void
    {
        $this->boot();
        $this->csrfGuard();
        $id = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['new_password'] ?? '');
        // Локальные пароли есть только у родителей; ученики/админ — через портал
        $st = Database::pdo()->prepare("SELECT id FROM users WHERE id=? AND role='parent'");
        $st->execute([$id]);
        if (!$st->fetch()) {
            flash_set('admin_error', 'Сброс локального пароля возможен только для родителей.');
        } elseif ($pass !== '' && strlen($pass) >= 6) {
            Auth::changePassword($id, $pass);
            flash_set('admin_ok', 'Пароль пользователя изменён.');
        } else {
            flash_set('admin_error', 'Пароль должен быть не короче 6 символов.');
        }
        redirect('/admin/users');
    }
}