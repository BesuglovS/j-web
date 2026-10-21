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
     * Оценки подгрупп включаются в таблицу класса: для «7 А» берутся классы
     * «7 А1», «7 А2» и т.п. (см. classGroupIds()), ученики и одноимённые
     * предметы подгрупп объединяются с предметами базового класса.
     *
     * Возвращает: ['class' => строка класса, 'subgroups' => названия подгрупп,
     * 'groups' => [...]]. В каждой группе: label, merged, subjects,
     * subject_ids, lesson_count, dates (даты уроков группы по возрастанию —
     * столбцы таблицы), avg, marks_count и students[] (student, marks[],
     * by_date[дата => оценки], avg, count). В средних участвуют только
     * итоговые попытки (is_current=1), не итоговые — показываются в списке
     * для контекста.
     */
    public static function classSummary(int $classId, int $quarterId = 0, int $subjectId = 0): array
    {
        $pdo = Database::pdo();
        $out = ['class' => [], 'subgroups' => [], 'groups' => []];

        if (!$classId) {
            return $out;
        }

        $c = $pdo->prepare('SELECT * FROM classes WHERE id=?');
        $c->execute([$classId]);
        $class = $c->fetch() ?: [];
        $out['class'] = $class;

        // класс + подгруппы («7 А1», «7 А2»): их оценки показываем в таблице класса
        $classIds = self::classGroupIds($classId);
        $subgroups = self::subgroupClasses($classId);
        $out['subgroups'] = array_map(static fn(array $r): string => (string)$r['name'], $subgroups);
        $in = implode(',', array_fill(0, count($classIds), '?'));

        // ученики класса и его подгрупп
        $st = $pdo->prepare("SELECT DISTINCT s.* FROM students s JOIN student_classes sc ON sc.student_id=s.id
                             WHERE sc.class_id IN ($in) AND s.is_active=1 ORDER BY s.last_name, s.first_name");
        $st->execute($classIds);
        $students = $st->fetchAll();

        // предметы класса и подгрупп: одноимённые схлопываются в один блок
        $subjectRows = self::subjectRowsForClasses($classIds);
        $nameGroups = self::nameGroups($subjectRows, $classId);
        $indexByRepId = [];
        foreach ($nameGroups as $i => $ng) {
            $indexByRepId[(int)$ng['representative']['id']] = $i;
        }
        $subjects = array_map(static fn(array $ng): array => $ng['representative'], $nameGroups);
        $subjectNames = [];
        foreach ($subjectRows as $sub) {
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

        // оценки класса и подгрупп (все попытки; в средних — только is_current=1)
        $sql = 'SELECT m.id, m.student_id, m.value, m.work_type, m.comment, m.is_retake, m.is_current,
                       m.attempt_date, l.date, l.topic, l.subject_id
                FROM marks m
                JOIN lessons l ON l.id=m.lesson_id
                WHERE l.class_id IN (' . $in . ')';
        $ar = $classIds;
        if ($from) { $sql .= ' AND l.date >= ?'; $ar[] = $from; }
        if ($to)   { $sql .= ' AND l.date <= ?'; $ar[] = $to; }
        $sql .= ' ORDER BY l.date, l.id, m.id';
        $st = $pdo->prepare($sql);
        $st->execute($ar);

        $bySubject = [];
        foreach ($st as $m) {
            $m['subject_name'] = $subjectNames[(int)$m['subject_id']] ?? '';
            $bySubject[(int)$m['subject_id']][] = $m;
        }

        // количество уроков по предметам за период и их даты (столбцы таблицы)
        $sql = 'SELECT subject_id, date FROM lessons WHERE class_id IN (' . $in . ')';
        $ar = $classIds;
        if ($from) { $sql .= ' AND date >= ?'; $ar[] = $from; }
        if ($to)   { $sql .= ' AND date <= ?'; $ar[] = $to; }
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
        foreach ($nameGroups as $i => $ng) {
            if (isset($used[$i])) {
                continue;
            }

            $groupSubjects = [$ng['representative']];
            $groupNameGroups = [$ng];
            $isMerged = false;
            if ($pair !== null) {
                $firstIdx = $indexByRepId[(int)$pair[0]['id']] ?? null;
                $secondIdx = $indexByRepId[(int)$pair[1]['id']] ?? null;
                if ($i === $firstIdx && $secondIdx !== null) {
                    $groupSubjects = [$nameGroups[$firstIdx]['representative'], $nameGroups[$secondIdx]['representative']];
                    $groupNameGroups = [$nameGroups[$firstIdx], $nameGroups[$secondIdx]];
                    $isMerged = true;
                } elseif ($i === $secondIdx) {
                    continue; // второй предмет пары уже вошёл в объединённую группу
                }
            }

            $used[$i] = true;
            if ($isMerged) {
                $used[$indexByRepId[(int)$pair[1]['id']]] = true;
            }

            $ids = [];
            foreach ($groupNameGroups as $gng) {
                foreach ($gng['ids'] as $id) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));

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
                    : (string)$ng['representative']['name'],
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
     * ID класса и его подгрупп: для «7 А» — ещё «7 А1», «7 А2», «7 А-1»
     * (название подгруппы = название класса + номер, пробелы игнорируются).
     *
     * @return int[]
     */
    public static function classGroupIds(int $classId): array
    {
        if (!$classId) {
            return [];
        }
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, name FROM classes WHERE id=?');
        $st->execute([$classId]);
        $base = $st->fetch();
        if (!$base) {
            return [$classId];
        }

        $ids = [$classId];
        $baseKey = SubjectMerge::normalize((string)$base['name']);
        if ($baseKey === '') {
            return $ids;
        }

        foreach ($pdo->query('SELECT id, name FROM classes') as $r) {
            $id = (int)$r['id'];
            if ($id === $classId) {
                continue;
            }
            $key = SubjectMerge::normalize((string)$r['name']);
            if ($key === '' || !str_starts_with($key, $baseKey)) {
                continue;
            }
            $suffix = substr($key, strlen($baseKey));
            if (preg_match('/^[-–—(\/]?\d{1,2}\)?$/u', $suffix)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Подгруппы класса (без самого класса), отсортированные по названию.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function subgroupClasses(int $classId): array
    {
        $ids = self::classGroupIds($classId);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $classId));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::pdo()->prepare('SELECT * FROM classes WHERE id IN (' . $in . ') ORDER BY name');
        $st->execute($ids);
        return $st->fetchAll();
    }

    /**
     * Предметы класса с учётом подгрупп: одноимённые предметы подгрупп
     * схлопываются в один (берётся строка базового класса/первая по порядку).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function subjectsForClass(int $classId): array
    {
        if (!$classId) {
            return [];
        }
        $rows = self::subjectRowsForClasses(self::classGroupIds($classId));
        return array_map(
            static fn(array $ng): array => $ng['representative'],
            self::nameGroups($rows, $classId)
        );
    }

    /**
     * Все строки предметов перечисленных классов.
     *
     * @param int[] $classIds
     * @return array<int, array<string, mixed>>
     */
    private static function subjectRowsForClasses(array $classIds): array
    {
        if (!$classIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($classIds), '?'));
        $st = Database::pdo()->prepare('SELECT * FROM subjects WHERE class_id IN (' . $in . ') ORDER BY name, id');
        $st->execute($classIds);
        return $st->fetchAll();
    }

    /**
     * Группировка предметов по нормализованному названию (без учёта регистра
     * и пробелов). Представитель группы — строка базового класса, иначе первая.
     *
     * @param array<int, array<string, mixed>> $subjects
     * @return array<int, array{name: string, representative: array<string, mixed>, subjects: array<int, array<string, mixed>>, ids: int[]}>
     */
    private static function nameGroups(array $subjects, int $baseClassId): array
    {
        $groups = [];
        foreach ($subjects as $sub) {
            $key = SubjectMerge::normalize((string)($sub['name'] ?? ''));
            if ($key === '') {
                $key = '#' . (int)($sub['id'] ?? 0);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name'           => (string)($sub['name'] ?? ''),
                    'representative' => $sub,
                    'subjects'       => [],
                    'ids'            => [],
                ];
            } elseif ((int)($sub['class_id'] ?? 0) === $baseClassId) {
                $groups[$key]['representative'] = $sub;
            }
            $groups[$key]['subjects'][] = $sub;
            $groups[$key]['ids'][] = (int)$sub['id'];
        }
        return array_values($groups);
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