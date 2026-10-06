<?php

/**
 * Единая логика записи оценок с учётом переписывания.
 *
 * Каждая строка marks — попытка в группе «клетка журнала» (ученик, урок,
 * work_type). Работы одного типа за разные уроки — независимые группы, поэтому
 * пересдача одной работы не может привязаться к другой работе того же типа.
 * Попытка переписывания (is_retake=1) привязывается к уроку (клетке), из
 * которой ставится; машиночитаемая дата попытки — attempt_date (у
 * переписывания это введённая вручную дата пересдачи).
 * Итоговая попытка группы (is_current=1) — последняя по attempt_date
 * (при равных датах приоритет у переписывания, затем по id); только она
 * участвует в средних.
 */
class MarkService
{
    /**
     * Обычная оценка (без истории): заменяет базовые (is_retake=0) строки клетки
     * (ученик + урок + work_type). Пустое значение удаляет базовые строки клетки.
     * История переписываний не затрагивается.
     *
     * Существующая базовая строка обновляется НА МЕСТЕ (id и created_at не
     * меняются), attempt_date при правке не перезаписывается.
     */
    public static function saveBase(int $studentId, int $lessonId, string $workType, ?int $value, string $comment = ''): void
    {
        $pdo = Database::pdo();
        if ($value === null) {
            $pdo->prepare('DELETE FROM marks WHERE student_id=? AND lesson_id=? AND work_type=? AND is_retake=0')
                ->execute([$studentId, $lessonId, $workType]);
        } else {
            $sel = $pdo->prepare('SELECT id FROM marks WHERE student_id=? AND lesson_id=? AND work_type=? AND is_retake=0 ORDER BY id LIMIT 1');
            $sel->execute([$studentId, $lessonId, $workType]);
            $existingId = $sel->fetchColumn();
            if ($existingId !== false) {
                $pdo->prepare('UPDATE marks SET value=?, comment=? WHERE id=? OR (student_id=? AND lesson_id=? AND work_type=? AND is_retake=0 AND id<>?)')
                    ->execute([$value, $comment, (int)$existingId, $studentId, $lessonId, $workType, (int)$existingId]);
            } else {
                $pdo->prepare('INSERT INTO marks (student_id, lesson_id, value, work_type, comment, attempt_date) VALUES (?,?,?,?,?,?)')
                    ->execute([$studentId, $lessonId, (int)$value, $workType, $comment, self::lessonDate($lessonId) ?? gmdate('Y-m-d')]);
            }
        }
        self::recomputeCurrent($studentId, $lessonId, $workType);
    }

    /**
     * Попытка переписывания: вставляется/обновляется с привязкой к уроку
     * (клетке), из которой ставится, — то есть к текущей открытой оценке,
     * а не к более поздней работе того же типа.
     * $attemptDate — машиночитаемая дата пересдачи (Y-m-d); при пустой/невалидной
     * значение берётся из комментария, затем сегодняшний день.
     * Возвращает id строки.
     */
    public static function saveRetake(int $studentId, string $workType, int $value, string $comment, int $lessonId, ?int $markId = null, ?string $attemptDate = null): int
    {
        $pdo = Database::pdo();
        $attemptDate = self::normalizeAttemptDate($attemptDate, $comment);
        if ($lessonId <= 0) {
            throw new InvalidArgumentException('saveRetake: lessonId is required');
        }
        if ($markId !== null && $markId > 0) {
            // Повторное сохранение существующей попытки (пересылка списка с UI)
            $pdo->prepare('UPDATE marks SET value=?, comment=?, lesson_id=?, attempt_date=? WHERE id=? AND student_id=? AND is_retake=1')
                ->execute([$value, $comment, $lessonId, $attemptDate, $markId, $studentId]);
            $id = $markId;
        } else {
            $pdo->prepare('INSERT INTO marks (student_id, lesson_id, value, work_type, comment, attempt_date, is_retake) VALUES (?,?,?,?,?,?,1)')
                ->execute([$studentId, $lessonId, $value, $workType, $comment, $attemptDate]);
            $id = (int)$pdo->lastInsertId();
        }
        self::recomputeCurrent($studentId, $lessonId, $workType);
        return $id;
    }

    /**
     * Удалить попытки по id (используется для удаления переписываний из UI).
     * Пересчитывает is_current затронутых групп.
     */
    public static function deleteByIds(array $ids, int $studentId): void
    {
        if (!$ids) {
            return;
        }
        $pdo = Database::pdo();
        $affected = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $st = $pdo->prepare(
                'SELECT m.id, m.lesson_id, m.work_type FROM marks m
                 WHERE m.id=? AND m.student_id=?'
            );
            $st->execute([$id, $studentId]);
            $row = $st->fetch();
            if ($row) {
                $affected[] = [(int)$row['lesson_id'], (string)$row['work_type']];
            }
        }
        $placeholders = implode(',', array_fill(0, count($affected), '?'));
        $idsInt = array_map('intval', $ids);
        $pdo->prepare("DELETE FROM marks WHERE id IN ($placeholders) AND student_id=?")
            ->execute([...$idsInt, $studentId]);
        foreach ($affected as [$lessonId, $workType]) {
            self::recomputeCurrent($studentId, $lessonId, $workType);
        }
    }

    /**
     * Пересчитать is_current для группы «клетка журнала»
     * (ученик, урок, work_type).
     * Итоговая попытка — с максимальной attempt_date; при равных датах
     * приоритет у переписывания, далее по id (последняя вставленная).
     */
    public static function recomputeCurrent(int $studentId, int $lessonId, string $workType): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare(
            'SELECT id, attempt_date, is_retake FROM marks
             WHERE student_id = ? AND lesson_id = ? AND work_type = ?'
        );
        $st->execute([$studentId, $lessonId, $workType]);
        $rows = $st->fetchAll();
        if (!$rows) {
            return;
        }
        usort($rows, static function ($a, $b) {
            $ad = (string)($a['attempt_date'] ?? '');
            $bd = (string)($b['attempt_date'] ?? '');
            if ($ad !== $bd) {
                return $bd <=> $ad;        // позднее — выше
            }
            $ar = (int)$a['is_retake'];
            $br = (int)$b['is_retake'];
            if ($ar !== $br) {
                return $br <=> $ar;        // переписывание приоритетнее при равных датах
            }
            return (int)$b['id'] <=> (int)$a['id']; // позже вставленная — выше
        });
        $latestId = (int)$rows[0]['id'];
        $in = implode(',', array_map('intval', array_column($rows, 'id')));
        $pdo->exec("UPDATE marks SET is_current = CASE WHEN id = $latestId THEN 1 ELSE 0 END WHERE id IN ($in)");
    }

    /**
     * Одноразовый пересчёт is_current по всем группам «клетка журнала»
     * (ученик, урок, work_type): нужен при переходе от группировки «по предмету»
     * к группировке «по уроку» и для устранения старых рассогласований.
     */
    public static function repairAllCurrent(): int
    {
        $groups = Database::pdo()->query(
            'SELECT DISTINCT student_id, lesson_id, work_type FROM marks'
        )->fetchAll();
        foreach ($groups as $g) {
            self::recomputeCurrent((int)$g['student_id'], (int)$g['lesson_id'], (string)$g['work_type']);
        }
        return count($groups);
    }

    /**
     * Валидная дата попытки: строка Y-m-d → как есть; иначе первое
     * ДД.ММ.ГГГГ (или Y-m-d) из комментария; иначе сегодняшний день.
     */
    public static function normalizeAttemptDate(?string $date, ?string $comment = null): string
    {
        $date = trim((string)$date);
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        $fromComment = ($comment !== null && trim($comment) !== '')
            ? self::parseDateFromComment((string)$comment)
            : null;
        return $fromComment ?? gmdate('Y-m-d');
    }

    /** Первое ДД.ММ.ГГГГ (или ДД.ММ.ГГ, или Y-m-d) в тексте комментария → Y-m-d. */
    public static function parseDateFromComment(string $comment): ?string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $comment, $m)) {
            return $m[1];
        }
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{2,4})/', $comment, $m)) {
            $y = (int)$m[3];
            if ($y < 100) {
                $y += 2000;
            }
            $mo = (int)$m[2];
            $day = (int)$m[1];
            if ($mo >= 1 && $mo <= 12 && $day >= 1 && $day <= 31) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $day);
            }
        }
        return null;
    }

    /** Дата урока (Y-m-d) или null. */
    public static function lessonDate(int $lessonId): ?string
    {
        $st = Database::pdo()->prepare('SELECT date FROM lessons WHERE id=?');
        $st->execute([$lessonId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    /** subject_id урока. */
    public static function lessonSubject(int $lessonId): ?int
    {
        $st = Database::pdo()->prepare('SELECT subject_id FROM lessons WHERE id=?');
        $st->execute([$lessonId]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int)$v;
    }
}
