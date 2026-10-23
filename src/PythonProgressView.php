<?php
declare(strict_types=1);

/**
 * Слоты шаблона admin/python_progress (страница успеваемости по python-курсу).
 *
 * Общая логика для админа (/admin/python-progress) и тьютора (/tutor/python):
 * вычисляет диапазоны уроков для отображения и начисления баллов из сводки
 * QuizProgressService::classSummary*() и GET-параметров фильтра.
 */
class PythonProgressView
{
    /**
     * Дата оценки прогресса из GET-параметра on_date (Y-m-d).
     * Пусто или некорректно — null (показывать текущий прогресс).
     */
    public static function asOfDate(array $get): ?string
    {
        $raw = trim((string)($get['on_date'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $raw);
        return $date !== false && $date->format('Y-m-d') === $raw ? $raw : null;
    }

    /**
     * Время оценки прогресса из GET-параметра on_time (HH:MM).
     * Пусто или некорректно — пустая строка (только дата / текущий прогресс).
     */
    public static function timeValue(array $get): string
    {
        $raw = trim((string)($get['on_time'] ?? ''));
        if ($raw === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw)) {
            return '';
        }
        return $raw;
    }

    /**
     * Дата и время оценки прогресса. Если время не указано, подставляется
     * время начала урока этого класса на выбранную дату из расписания.
     *
     * Время в фильтре — локальное (как в расписании журнала); для внешних
     * сервисов, которые хранят даты в UTC, until переводится в UTC.
     *
     * @return array{date:?string, time:string, until:?string}
     *   until — точка отсчёта в UTC (Y-m-d H:i:s) для API источников
     */
    public static function asOf(array $get, int $classId): array
    {
        $date = self::asOfDate($get);
        $time = self::timeValue($get);
        if ($date !== null && $time === '') {
            $time = self::lessonStartTime($classId, $date) ?? '';
        }

        $until = null;
        if ($date !== null) {
            $local = new DateTime(
                $date . ' ' . ($time !== '' ? $time : '23:59:59'),
                new DateTimeZone((string)(config()['display_timezone'] ?? 'UTC'))
            );
            $local->setTimezone(new DateTimeZone('UTC'));
            $until = $local->format('Y-m-d H:i:s');
        }

        return ['date' => $date, 'time' => $time, 'until' => $until];
    }

    /**
     * Время начала урока класса на дату из расписания (журнала занятий).
     * Если в этот день у класса несколько уроков, предпочитается занятие
     * по python/программированию, затем информатике, иначе — самый ранний.
     */
    public static function lessonStartTime(int $classId, string $date): ?string
    {
        if ($classId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $st = Database::pdo()->prepare(
            "SELECT l.start_time
             FROM lessons l
             JOIN subjects s ON s.id = l.subject_id
             WHERE l.class_id = ? AND l.date = ?
               AND l.start_time IS NOT NULL AND TRIM(l.start_time) <> ''
             ORDER BY CASE
                        WHEN s.name LIKE '%ython%' OR s.name LIKE '%рограмм%' THEN 0
                        WHEN s.name LIKE '%нформат%' THEN 1
                        ELSE 2
                      END,
                      l.start_time ASC, l.id ASC
             LIMIT 1"
        );
        $st->execute([$classId, $date]);
        $time = $st->fetchColumn();
        if ($time === false) {
            return null;
        }
        $time = substr((string)$time, 0, 5);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : null;
    }

    /**
     * @param array|null $summary    результат classSummary()/classSummaryServer()
     * @param int        $maxLessons всего уроков курса
     * @param array      $get        GET-параметры фильтра
     * @return array{lessonFrom:int, lessonTo:int, quizFrom:int, quizTo:int, taskFrom:int, taskTo:int, finalN:int, taskLimitOn:bool, taskLimit:int}
     */
    public static function slots(?array $summary, int $maxLessons, array $get): array
    {
        // Итоговый тест как виртуальный урок: sentinel = maxLessons+1
        $finalN = $maxLessons + 1;
        // Диапазон отображаемых уроков; по умолчанию 1..last(данные класса)
        $lessonFrom = 1;
        $lessonTo = $summary['max_lesson'] ?? 0;
        if ($lessonTo < 1) {
            $lessonTo = 0;
        }
        if (isset($get['lesson_from'])) {
            $lessonFrom = max(1, min($maxLessons, (int)$get['lesson_from']));
        }
        if (isset($get['lesson_to'])) {
            $lessonTo = max(0, min($maxLessons, (int)$get['lesson_to']));
        }
        // «Итог» можно выбрать в любом из селектов
        if (isset($get['lesson_from']) && (int)$get['lesson_from'] === $finalN) {
            $lessonFrom = $finalN;
        }
        if (isset($get['lesson_to']) && (int)$get['lesson_to'] === $finalN) {
            $lessonTo = $finalN;
        }
        if ($lessonTo > 0 && $lessonTo < $lessonFrom) {
            // автопомена при инверсном диапазоне (работает и с sentinel «Итог»)
            [$lessonFrom, $lessonTo] = [$lessonTo, $lessonFrom];
        }

        // Диапазоны начисления баллов независимы от диапазона отображения:
        // баллы квизов — уроки с квизом, сданным на 100%; задачи — решённые задачи.
        $lastQuizLesson = 0;
        $lastTaskLesson = 0;
        foreach (($summary['students'] ?? []) as $st) {
            foreach (($st['lessons'] ?? []) as $num => $l) {
                $num = (int)$num;
                if ($num < 1) {
                    continue;
                }
                if (($l['quiz'] ?? null) !== null && $num > $lastQuizLesson) {
                    $lastQuizLesson = $num;
                }
                if (($l['total'] ?? null) !== null && (int)$l['total'] > 0 && $num > $lastTaskLesson) {
                    $lastTaskLesson = $num;
                }
            }
        }
        $quizFrom = isset($get['quiz_from']) ? max(1, min($maxLessons, (int)$get['quiz_from'])) : 1;
        $quizTo   = isset($get['quiz_to'])   ? max(1, min($maxLessons, (int)$get['quiz_to']))   : ($lastQuizLesson ?: $maxLessons);
        $taskFrom = isset($get['task_from']) ? max(1, min($maxLessons, (int)$get['task_from'])) : 1;
        $taskTo   = isset($get['task_to'])   ? max(1, min($maxLessons, (int)$get['task_to']))   : ($lastTaskLesson ?: $maxLessons);
        if ($quizTo < $quizFrom) {
            [$quizFrom, $quizTo] = [$quizTo, $quizFrom];
        }
        if ($taskTo < $taskFrom) {
            [$taskFrom, $taskTo] = [$taskTo, $taskFrom];
        }

        // Ограничение числа задач последнего урока диапазона задач: при включённом
        // переключателе в общую сумму баллов идёт не более task_limit задач
        // последнего урока; пустое значение = учитывать все задачи (как при выключенном).
        $taskLimitOn = isset($get['task_limit_on']) && $get['task_limit_on'] === '1';
        $taskLimit   = isset($get['task_limit']) ? max(0, (int)$get['task_limit']) : 0;

        return compact('lessonFrom', 'lessonTo', 'quizFrom', 'quizTo', 'taskFrom', 'taskTo', 'finalN', 'taskLimitOn', 'taskLimit');
    }
}
