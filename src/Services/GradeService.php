<?php

/**
 * Расчёт сводок по оценкам (среднее арифметическое).
 */
class GradeService
{
    /**
     * Сводная таблица по классу/предмету/периоду.
     * Возвращает: students[], subjects[], quarter, rows: [studentId][columns]
     * где column = 'marks'|'avg' или строка subjectId.
     */
    public static function report(int $classId, int $quarterId = 0, int $subjectId = 0): array
    {
        $pdo = Database::pdo();
        $out = ['students' => [], 'subjects' => [], 'markSets' => []];

        if (!$classId) {
            return $out;
        }

        $students = $pdo->prepare('SELECT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name');
        $students->execute([$classId]);
        $out['students'] = $students->fetchAll();

        // диапазон периода
        $from = null; $to = null;
        if ($quarterId) {
            $q = $pdo->prepare('SELECT * FROM quarters WHERE id=?');
            $q->execute([$quarterId]);
            if ($q = $q->fetch()) { $from = $q['start_date']; $to = $q['end_date']; }
        }

        // список предметов, привязанных к выбранному классу
        $subj = $pdo->prepare('SELECT * FROM subjects WHERE class_id=? ORDER BY name');
        $subj->execute([$classId]);
        $subjects = $out['subjects'] = $subj->fetchAll();

        // по каждому предмету (или одному) получаем оценки всех студентов класса за период
foreach ($subjects as $sub) {
            if ($subjectId && (int)$sub['id'] !== $subjectId) continue;
            $sql = 'SELECT m.student_id, m.value, m.work_type FROM marks m
                    JOIN lessons l ON l.id=m.lesson_id
                    WHERE l.subject_id=:sid AND l.class_id=:cid';
            $ar = [':sid'=>$sub['id'], ':cid'=>$classId];
            if ($from) { $sql .= ' AND l.date >= :from'; $ar[':from']=$from; }
            if ($to)   { $sql .= ' AND l.date <= :to';   $ar[':to']=$to; }
            $st = $pdo->prepare($sql);
            $st->execute($ar);
            $out['markSets'][(int)$sub['id']] = $st->fetchAll();
        }
        return $out;
    }

    /**
     * Средний балл по студенту и предмету (по диапазону дат или всему периоду).
     */
    public static function averageForSubject(int $studentId, int $subjectId, ?string $from=null, ?string $to=null): ?float
    {
        $sql = 'SELECT AVG(m.value) FROM marks m JOIN lessons l ON l.id=m.lesson_id
                WHERE m.student_id=:sid AND l.subject_id=:sub';
        $ar = [':sid'=>$studentId, ':sub'=>$subjectId];
        if ($from) { $sql .= ' AND l.date >= :from'; $ar[':from']=$from; }
        if ($to)   { $sql .= ' AND l.date <= :to';   $ar[':to']=$to; }
        $st = Database::pdo()->prepare($sql);
        $st->execute($ar);
        $v = $st->fetchColumn();
        return $v === null ? null : round((float)$v, 2);
    }

    /**
     * Все оценки ученика сгруппированные по предмету (среднее + список).
     */
    public static function perSubject(int $studentId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT sub.name AS subject, sub.short_name, m.value, m.work_type, m.comment, l.date, l.topic
             FROM marks m
             JOIN lessons l ON l.id=m.lesson_id
             JOIN subjects sub ON sub.id=l.subject_id
             WHERE m.student_id=?
             ORDER BY sub.name, l.date, l.id'
        );
        $st->execute([$studentId]);
        $rows = $st->fetchAll();
        $grouped = [];
        foreach ($rows as $m) {
            $s = $m['subject'];
            if (!isset($grouped[$s])) $grouped[$s] = ['values'=>[], 'items'=>[], 'avg'=>null];
            $grouped[$s]['values'][] = (int)$m['value'];
            $grouped[$s]['items'][] = $m;
        }
        foreach ($grouped as $s => &$g) {
            $g['avg'] = count($g['values']) ? round(array_sum($g['values']) / count($g['values']), 2) : null;
        }
        return $grouped;
    }
}