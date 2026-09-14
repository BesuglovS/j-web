<?php

class ParentController
{
    private function boot(): void
    {
        Auth::requireRole('parent');
        // родители — read-only зеркало auth-web, подтягиваем перед показом
        ParentService::ensureSynced();
    }

    private function parent(): ?array
    {
        $st = Database::pdo()->prepare('SELECT * FROM parents WHERE user_id=?');
        $st->execute([Auth::id()]);
        return $st->fetch() ?: null;
    }

    private function children(int $parentId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT s.*, c.name AS class_name FROM student_parent sp
             JOIN students s ON s.id=sp.student_id
             JOIN classes c ON c.id=s.class_id
             WHERE sp.parent_id=? ORDER BY s.last_name'
        );
        $st->execute([$parentId]);
        $children = $st->fetchAll();
        // все группы каждого ребёнка (класс + подгруппы)
        $cn = Database::pdo()->prepare('SELECT c.name FROM student_classes sc JOIN classes c ON c.id=sc.class_id WHERE sc.student_id=? ORDER BY c.name');
        foreach ($children as &$c) {
            $cn->execute([(int)$c['id']]);
            $names = $cn->fetchAll(PDO::FETCH_COLUMN);
            $c['all_class_names'] = implode(', ', $names) ?: (string)($c['class_name'] ?? '');
        }
        unset($c);
        return $children;
    }

    public function index(): string
    {
        $this->boot();
        $p = $this->parent();
        if (!$p) {
            return View::render('my/empty', ['note' => 'Для вашей учётной записи не привязан родитель. Обратитесь к администратору.']);
        }
        $children = $this->children((int)$p['id']);
        return View::render('parent/index', ['parent' => $p, 'children' => $children]);
    }

    public function child(array $params): string
    {
        $this->boot();
        $p = $this->parent();
        if (!$p) return View::render('my/empty', ['note' => 'Нет привязки к родителю.']);
        $childId = (int)$params[0];
        $pid = (int)$p['id'];
        // проверка, что ребёнок принадлежит родителю
        $chk = Database::pdo()->prepare('SELECT 1 FROM student_parent WHERE student_id=? AND parent_id=?');
        $chk->execute([$childId, $pid]);
        if (!$chk->fetch()) {
            http_response_code(403);
            exit('Доступ запрещён: не ваш ребёнок.');
        }
        $st = Database::pdo()->prepare('SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id=s.class_id WHERE s.id=?');
        $st->execute([$childId]);
        $child = $st->fetch();
        if (!$child) return View::render('my/empty', ['note' => 'Студент не найден.']);

        $sid = (int)$child['id'];
        $pdo = Database::pdo();
        // все группы ребёнка (класс + подгруппы)
        $st = $pdo->prepare('SELECT class_id FROM student_classes WHERE student_id=?');
        $st->execute([$sid]);
        $classIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$classIds && $child['class_id']) {
            $classIds = [(int)$child['class_id']];
        }
        if (!$classIds) {
            $classIds = [0];
        }
        $hwFilter = implode(',', array_fill(0, count($classIds), '?'));
        $st = $pdo->prepare('SELECT c.name FROM student_classes sc JOIN classes c ON c.id=sc.class_id WHERE sc.student_id=? ORDER BY c.name');
        $st->execute([$sid]);
        $names = $st->fetchAll(PDO::FETCH_COLUMN);
        $child['all_class_names'] = implode(', ', $names) ?: (string)($child['class_name'] ?? '');

        $bySubject = GradeService::perSubject($sid);
        $hw = $pdo->prepare("SELECT hw.*, sub.name AS subject, l.date AS lesson_date, c.name AS class_name,
                    (SELECT hws.status FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_status,
                    (SELECT hws.result_mark FROM homework_submissions hws WHERE hws.homework_id=hw.id AND hws.student_id=?) AS my_mark
                    FROM homeworks hw JOIN lessons l ON l.id=hw.lesson_id JOIN subjects sub ON sub.id=l.subject_id JOIN classes c ON c.id=l.class_id
                    WHERE l.class_id IN ($hwFilter) ORDER BY COALESCE(hw.due_date,l.date) DESC");
        $hw->execute(array_merge([$sid, $sid], $classIds));
        $remarks = $pdo->prepare('SELECT r.text, r.created_at, l.date, sub.name AS subject, c.name AS class_name FROM lesson_remarks r JOIN lessons l ON l.id=r.lesson_id JOIN subjects sub ON sub.id=l.subject_id JOIN classes c ON c.id=l.class_id WHERE r.student_id=? ORDER BY r.created_at DESC');
        $remarks->execute([$sid]);

        return View::render('parent/child', [
            'parent' => $p,
            'child' => $child,
            'bySubject' => $bySubject,
            'homeworks' => $hw->fetchAll(),
            'remarks' => $remarks->fetchAll(),
        ]);
    }
}