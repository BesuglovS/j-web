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
     * @param array|null $summary    результат classSummary()/classSummaryServer()
     * @param int        $maxLessons всего уроков курса
     * @param array      $get        GET-параметры фильтра
     * @return array{lessonFrom:int, lessonTo:int, quizFrom:int, quizTo:int, taskFrom:int, taskTo:int, finalN:int}
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

        return compact('lessonFrom', 'lessonTo', 'quizFrom', 'quizTo', 'taskFrom', 'taskTo', 'finalN');
    }
}
