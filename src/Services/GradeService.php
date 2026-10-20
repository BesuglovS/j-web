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
            // В средних участвуют только итоговые попытки (последнее переписывание)
            $sql = 'SELECT m.student_id, m.value, m.work_type FROM marks m
                    JOIN lessons l ON l.id=m.lesson_id
                    WHERE l.subject_id=:sid AND l.class_id=:cid AND m.is_current=1';
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
     * Детальная сводка класса по предметам: по каждому предмету — список
     * учеников с их оценками и средним баллом.
     *
     * Объединённые предметы (SubjectMerge: 9 класс — «Информатика» + «Труд»,
     * 8 класс — «Информатика» + «Моделирование и пилотирование БАС»,
     * 7 класс — «Информатика» + «Инженерия БАС») показываются как один блок:
     * оценки обоих предметов и общий средний.
     *
     * Возвращает: ['class' => строка класса, 'groups' => [...]]. В каждой
     * группе: label, merged, subjects, subject_ids, lesson_count, dates
     * (даты уроков группы по возрастанию — столбцы таблицы), avg, marks_count
     * и students[] (student, marks[], by_date[дата => оценки], avg, count).
     * В средних участвуют только итоговые попытки (is_current=1),
     * не итоговые — показываются в списке для контекста.
     */
    public static function classSummary(int $classId, int $quarterId = 0, int $subjectId = 0): array
    {
        $pdo = Database::pdo();
        $out = ['class' => [], 'groups' => []];

        if (!$classId) {
            return $out;
        }

        $c = $pdo->prepare('SELECT * FROM classes WHERE id=?');
        $c->execute([$classId]);
        $class = $c->fetch() ?: [];
        $out['class'] = $class;

        // ученики класса
        $st = $pdo->prepare('SELECT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id
                             WHERE sc.class_id=? AND s.is_active=1 ORDER BY s.last_name, s.first_name');
        $st->execute([$classId]);
        $students = $st->fetchAll();

        // предметы класса
        $st = $pdo->prepare('SELECT * FROM subjects WHERE class_id=? ORDER BY name');
        $st->execute([$classId]);
        $subjects = $st->fetchAll();
        $subjectNames = [];
        foreach ($subjects as $sub) {
            $subjectNames[(int)$sub['id']] = (string)$sub['name'];
        }

        // диапазон периода
        $from = null;
        $to = null;
        if ($quarterId) {
            $q = $pdo->prepare('SELECT * FROM quarters WHERE id=?');
            $q->execute([$quarterId]);
            if ($qq = $q->fetch()) {
                $from = $qq['start_date'];
                $to = $qq['end_date'];
            }
        }

        // оценки класса (все попытки; в средних — только is_current=1)
        $sql = 'SELECT m.id, m.student_id, m.value, m.work_type, m.comment, m.is_retake, m.is_current,
                       m.attempt_date, l.date, l.topic, l.subject_id
                FROM marks m
                JOIN lessons l ON l.id=m.lesson_id
                WHERE l.class_id=:cid';
        $ar = [':cid' => $classId];
        if ($from) { $sql .= ' AND l.date >= :from'; $ar[':from'] = $from; }
        if ($to)   { $sql .= ' AND l.date <= :to';   $ar[':to'] = $to; }
        $sql .= ' ORDER BY l.date, l.id, m.id';
        $st = $pdo->prepare($sql);
        $st->execute($ar);

        $bySubject = [];
        foreach ($st as $m) {
            $m['subject_name'] = $subjectNames[(int)$m['subject_id']] ?? '';
            $bySubject[(int)$m['subject_id']][] = $m;
        }

        // количество уроков по предметам за период и их даты (столбцы таблицы)
        $sql = 'SELECT subject_id, date FROM lessons WHERE class_id=:cid';
        $ar = [':cid' => $classId];
        if ($from) { $sql .= ' AND date >= :from'; $ar[':from'] = $from; }
        if ($to)   { $sql .= ' AND date <= :to';   $ar[':to'] = $to; }
        $sql .= ' ORDER BY date, id';
        $st = $pdo->prepare($sql);
        $st->execute($ar);
        $lessonCounts = [];
        $lessonDates = [];
        foreach ($st as $r) {
            $sid = (int)$r['subject_id'];
            $lessonCounts[$sid] = ($lessonCounts[$sid] ?? 0) + 1;
            $lessonDates[$sid][(string)$r['date']] = true;
        }

        // объединённые предметы (пара применяется, только если есть оба предмета)
        $grade = (isset($class['grade']) && $class['grade'] !== null && $class['grade'] !== '')
            ? (int)$class['grade']
            : null;
        $pair = SubjectMerge::findPair($grade, (string)($class['name'] ?? ''), $subjects);

        $groups = [];
        $used = [];
        foreach ($subjects as $sub) {
            $sid = (int)$sub['id'];
            if (isset($used[$sid])) {
                continue;
            }

            $groupSubjects = [$sub];
            $isMerged = false;
            if ($pair !== null && $sid === (int)$pair[0]['id']) {
                $groupSubjects = $pair;
                $isMerged = true;
            } elseif ($pair !== null && $sid === (int)$pair[1]['id']) {
                continue; // второй предмет пары уже вошёл в объединённую группу
            }

            $ids = array_map(static fn(array $s): int => (int)$s['id'], $groupSubjects);
            foreach ($ids as $id) {
                $used[$id] = true;
            }

            // фильтр по предмету: показываем только группу с выбранным предметом
            if ($subjectId && !in_array($subjectId, $ids, true)) {
                continue;
            }

            $lessonCount = 0;
            $dates = [];
            foreach ($ids as $id) {
                $lessonCount += $lessonCounts[$id] ?? 0;
                foreach ($lessonDates[$id] ?? [] as $d => $_) {
                    $dates[$d] = true;
                }
            }
            ksort($dates);

            $rows = [];
            $allValues = [];
            foreach ($students as $s) {
                $sMarks = [];
                foreach ($ids as $id) {
                    foreach ($bySubject[$id] ?? [] as $m) {
                        if ((int)$m['student_id'] === (int)$s['id']) {
                            $sMarks[] = $m;
                        }
                    }
                }
                usort($sMarks, static function (array $a, array $b): int {
                    return strcmp((string)$a['date'], (string)$b['date']) ?: ((int)$a['id'] <=> (int)$b['id']);
                });
                $vals = [];
                $byDate = [];
                foreach ($sMarks as $m) {
                    $byDate[(string)$m['date']][] = $m;
                    if ((int)$m['is_current']) {
                        $vals[] = (int)$m['value'];
                    }
                }
                $allValues = array_merge($allValues, $vals);
                $rows[] = [
                    'student' => $s,
                    'marks'   => $sMarks,
                    'by_date' => $byDate,
                    'avg'     => $vals ? round(array_sum($vals) / count($vals), 2) : null,
                    'count'   => count($vals),
                ];
            }

            $groups[] = [
                'label'        => $isMerged
                    ? SubjectMerge::displayName($groupSubjects[0], $groupSubjects[1])
                    : (string)$sub['name'],
                'merged'       => $isMerged,
                'subjects'     => $groupSubjects,
                'subject_ids'  => $ids,
                'lesson_count' => $lessonCount,
                'dates'        => array_keys($dates),
                'students'     => $rows,
                'avg'          => $allValues ? round(array_sum($allValues) / count($allValues), 2) : null,
                'marks_count'  => count($allValues),
            ];
        }

        $out['groups'] = $groups;
        return $out;
    }

    /**
     * Средний балл по студенту и предмету (по диапазону дат или всему периоду).
     */
    public static function averageForSubject(int $studentId, int $subjectId, ?string $from=null, ?string $to=null): ?float
    {
        $sql = 'SELECT AVG(m.value) FROM marks m JOIN lessons l ON l.id=m.lesson_id
                WHERE m.student_id=:sid AND l.subject_id=:sub AND m.is_current=1';
        $ar = [':sid'=>$studentId, ':sub'=>$subjectId];
        if ($from) { $sql .= ' AND l.date >= :from'; $ar[':from']=$from; }
        if ($to)   { $sql .= ' AND l.date <= :to';   $ar[':to']=$to; }
        $st = Database::pdo()->prepare($sql);
        $st->execute($ar);
        $v = $st->fetchColumn();
        return $v === null ? null : round((float)$v, 2);
    }

    /**
     * Все попытки оценок ученика, сгруппированные по предмету.
     * Среднее — только по итоговым попыткам (последнее переписывание);
     * в items флаг is_current для отображения старых попыток серым.
     */
    public static function perSubject(int $studentId): array
    {
        $st = Database::pdo()->prepare(
            'SELECT sub.name AS subject, sub.short_name, m.value, m.work_type, m.comment, m.is_retake, m.is_current, l.date, l.topic, l.class_id, c.name AS class_name
             FROM marks m
             JOIN lessons l ON l.id=m.lesson_id
             JOIN subjects sub ON sub.id=l.subject_id
             LEFT JOIN classes c ON c.id=l.class_id
             WHERE m.student_id=?
             ORDER BY sub.name, l.date, l.id'
        );
        $st->execute([$studentId]);
        $rows = $st->fetchAll();
        $grouped = [];
        foreach ($rows as $m) {
            $s = $m['subject'];
            if (!isset($grouped[$s])) $grouped[$s] = ['values'=>[], 'items'=>[], 'avg'=>null];
            if ((int)$m['is_current']) {
                $grouped[$s]['values'][] = (int)$m['value'];
            }
            $grouped[$s]['items'][] = $m;
        }
        foreach ($grouped as $s => &$g) {
            $g['avg'] = count($g['values']) ? round(array_sum($g['values']) / count($g['values']), 2) : null;
        }
        return $grouped;
    }
}