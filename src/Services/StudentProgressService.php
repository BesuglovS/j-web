<?php
declare(strict_types=1);

/**
 * Прогресс текущего ученика по python-курсу: квизы уроков (python-web) и
 * решённые задачи контестов (contest-web). Данные берутся по HTTP от
 * серверных API соседних проектов под SSO-сессией самого ученика
 * (кука auth_session, как в AuthClient), поэтому доступны только
 * «свои» данные. Ответы кэшируются в сессии (TTL 5 минут).
 */
class StudentProgressService
{
    private const CACHE_TTL = 300;

    /**
     * Квизы ученика (python-web sandbox/progress.php).
     * Возвращает ['progress' => [{lesson_number, completed, quiz_score}...]] или null при ошибке.
     */
    public static function myPythonProgress(bool $bypassCache = false): ?array
    {
        return self::apiGet(config()['python_progress_url'], 'py_my', $bypassCache);
    }

    /**
     * Решённые задачи по контестам (contest-web api/my_progress).
     * Возвращает ['contests' => {"<id>": {solved, total}}] или null при ошибке.
     */
    public static function myContestProgress(bool $bypassCache = false): ?array
    {
        return self::apiGet(config()['contest_my_progress_url'], 'ct_my', $bypassCache);
    }

    /**
     * Сводка для страницы /my: строки уроков с квизом и прогрессом контеста.
     * Возвращает [
     *   'lessons'   => [{num, quiz, solved, total, has_contest, quiz_url, contest_id}...],
     *   'final'     => ?int,                               // результат итогового теста (-1)
     *   'final_url' => string,                             // страница итогового теста
     *   'errors'    => string[],
     * ] или null, если оба источника недоступны.
     */
    public static function mySummary(bool $bypassCache = false): ?array
    {
        $quizRaw = self::myPythonProgress($bypassCache);
        $contestRaw = self::myContestProgress($bypassCache);
        if ($quizRaw === null && $contestRaw === null) {
            return null;
        }

        $lessonContests = config()['python_lesson_contests'];
        $lessonPages = self::lessonPages($bypassCache); // num => страница урока c квизом
        $pythonSiteUrl = config()['python_site_url'];

        $solvedByContest = [];
        foreach (($contestRaw['contests'] ?? []) as $cid => $c) {
            $solvedByContest[(int) $cid] = ['solved' => (int) ($c['solved'] ?? 0), 'total' => (int) ($c['total'] ?? 0)];
        }

        $row = function (int $num, ?int $quiz, int $solved, ?int $total, bool $hasContest, int $cid) use ($lessonPages, $pythonSiteUrl): array {
            return [
                'num'         => $num,
                'quiz'        => $quiz,
                'solved'      => $solved,
                'total'       => $total,
                'has_contest' => $hasContest,
                'quiz_url'    => isset($lessonPages[$num]) ? $pythonSiteUrl . '/' . $lessonPages[$num] : '',
                'contest_id'  => $hasContest && $cid > 0 ? $cid : 0,
            ];
        };

        $lessons = [];
        $final = null;
        foreach (($quizRaw['progress'] ?? []) as $p) {
            $num = (int) ($p['lesson_number'] ?? 0);
            if ($num === -1) {
                if ($p['quiz_score'] !== null) {
                    $final = (int) $p['quiz_score'];
                }
                continue;
            }
            if ($num < 1 || $num > 50) {
                continue;
            }
            $cid = $lessonContests[$num] ?? 0;
            $solved = 0;
            $total = null;
            if ($cid && isset($solvedByContest[$cid])) {
                $solved = $solvedByContest[$cid]['solved'];
                $total = $solvedByContest[$cid]['total'];
                unset($solvedByContest[$cid]);
            }
            if (($p['quiz_score'] ?? null) === null && $solved === 0) {
                continue; // урок без результатов вообще — в таблицу не включаем
            }
            $lessons[] = $row($num, (isset($p['quiz_score']) && $p['quiz_score'] !== null) ? (int) $p['quiz_score'] : null, $solved, $total, $cid > 0, $cid);
        }

        // контесты, решённые без привязки к пройденному уроку, всё равно показываем
        $takenNums = array_column($lessons, 'num');
        foreach ($solvedByContest as $cid => $c) {
            $num = (int) array_search($cid, $lessonContests, true);
            if ($num > 0 && !in_array($num, $takenNums, true)) {
                $lessons[] = $row($num, null, (int) $c['solved'], (int) $c['total'], true, $cid);
            }
        }

        usort($lessons, fn(array $a, array $b): int => $a['num'] <=> $b['num']);

        $errors = [];
        if ($quizRaw === null) {
            $errors[] = 'python-web: не удалось получить прогресс квизов';
        }
        if ($contestRaw === null) {
            $errors[] = 'contest-web: не удалось получить прогресс задач';
        }

        return [
            'lessons'   => $lessons,
            'final'     => $final,
            'final_url' => config()['python_site_url'] . '/final-test.html',
            'errors'    => $errors,
        ];
    }

    /**
     * Метаданные уроков python-web (lessons.json): num → страница урока.
     * Структура JSON: sections[].lessons[].{num, file} (file уже с расширением .html).
     * Возвращает [num => file] (пусто, если lessons.json недоступен).
     */
    private static function lessonPages(bool $bypassCache = false): array
    {
        $raw = self::apiGet(config()['python_lessons_url'], 'py_lessons', $bypassCache);
        $map = [];
        foreach (($raw['sections'] ?? []) as $section) {
            foreach (($section['lessons'] ?? []) as $lesson) {
                $num = (int) ($lesson['num'] ?? 0);
                $file = (string) ($lesson['file'] ?? '');
                if ($num > 0 && $file !== '') {
                    $map[$num] = $file;
                }
            }
        }
        return $map;
    }

    private static function apiGet(string $url, string $cacheKey, bool $bypassCache = false): ?array
    {
        if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
            $cached = $_SESSION['sp_' . $cacheKey] ?? null;
            $cachedAt = $_SESSION['sp_at_' . $cacheKey] ?? 0;
            if (is_array($cached) && (time() - $cachedAt) < self::CACHE_TTL) {
                return $cached;
            }
        }

        $cookedCookie = '';
        if (!empty($_COOKIE['auth_session'])) {
            // В куку допускаются не все символы — оставим безопасные.
            $safe = preg_replace('/[^A-Za-z0-9,_\-]/', '', (string) $_COOKIE['auth_session']);
            if ($safe !== '') {
                $cookedCookie = 'auth_session=' . $safe;
            }
        }

        try {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ];
            if ($cookedCookie !== '') {
                $opts[CURLOPT_COOKIE] = $cookedCookie;
            }
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } catch (Throwable $e) {
            return null;
        }

        if ($response === false || $httpCode !== 200) {
            return null;
        }
        $data = json_decode((string) $response, true);
        if (!is_array($data)) {
            return null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['sp_' . $cacheKey] = $data;
            $_SESSION['sp_at_' . $cacheKey] = time();
        }
        return $data;
    }
}
