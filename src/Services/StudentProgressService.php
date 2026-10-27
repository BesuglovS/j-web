<?php
declare(strict_types=1);

/**
 * Прогресс ученика по python-курсу: квизы уроков (python-web) и решённые
 * задачи контестов (contest-web). Для страницы /my данные берутся под
 * SSO-сессией самого ученика (кука auth_session, как в AuthClient) — только
 * «свои». Для родителя (карточка ребёнка) те же отчёты запрашиваются
 * сервер-к-серверу по external_id ученика (доверие — по IP на стороне API).
 * Ответы кэшируются в сессии (TTL 5 минут).
 */
class StudentProgressService
{
    private const CACHE_TTL = 300;

    /**
     * Квизы ученика (python-web sandbox/progress.php).
     * При $studentId — сервер-к-сервер чтение прогресса конкретного ученика
     * (карточка ребёнка у родителя); иначе — «свой» прогресс по SSO-куке.
     * Возвращает ['progress' => [{lesson_number, completed, quiz_score}...]] или null при ошибке.
     */
    public static function myPythonProgress(bool $bypassCache = false, ?int $studentId = null): ?array
    {
        if ($studentId !== null) {
            return self::apiGet(
                config()['python_progress_url'] . '?user_id=' . $studentId,
                'py_u_' . $studentId,
                $bypassCache,
                true
            );
        }
        return self::apiGet(config()['python_progress_url'], 'py_my', $bypassCache);
    }

    /**
     * Решённые задачи по контестам (contest-web api/my_progress).
     * При $studentId — сервер-к-сервер чтение прогресса конкретного ученика.
     * Возвращает ['contests' => {"<id>": {solved, total}}] или null при ошибке.
     */
    public static function myContestProgress(bool $bypassCache = false, ?int $studentId = null): ?array
    {
        if ($studentId !== null) {
            return self::apiGet(
                config()['contest_my_progress_url'] . '&user_id=' . $studentId,
                'ct_u_' . $studentId,
                $bypassCache,
                true
            );
        }
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
        return self::summary(null, $bypassCache);
    }

    /**
     * Сводка по прогрессу конкретного ученика (для родителя, карточка ребёнка).
     * Данные запрашиваются сервер-к-серверу по external_id ученика.
     * Возвращает ту же структуру, что mySummary(), или null.
     */
    public static function studentSummary(int $studentId, bool $bypassCache = false): ?array
    {
        return self::summary($studentId, $bypassCache);
    }

    /**
     * Прогресс ученика на начало каждого урока класса.
     *
     * Для каждого занятия из расписания класса берётся сводка python/contest
     * на момент его начала (локальное время урока переводится в UTC).
     * Это позволяет увидеть, с каким запасом квизов и задач ученик подошёл
     * к каждому уроку, а не только текущий результат.
     *
     * @param bool $server true — сервер-к-сервер (кабинет тьютора), false — под SSO-кукой админа
     * @return array{
     *   className:string,
     *   lessons:array<int,array{
     *     lesson_id:int,date:string,time:string,subject:string,homework:string,future:bool,
     *     quiz_passed:?int,quiz_attempted:?int,tasks_solved:?int,tasks_total:?int,points:?int
     *   }>,
     *   errors:string[]
     * }
     */
    public static function lessonTimeline(int $classId, int $externalId, bool $bypassCache = false, bool $server = false): array
    {
        $empty = ['className' => '', 'lessons' => [], 'errors' => []];
        if ($classId <= 0 || $externalId <= 0) {
            return $empty;
        }

        $cacheKey = 'sp_tl_' . $classId . '_' . $externalId . ($server ? '_s' : '');
        $atKey = 'sp_tl_at_' . $classId . '_' . $externalId . ($server ? '_s' : '');
        if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
            $cached = $_SESSION[$cacheKey] ?? null;
            $cachedAt = $_SESSION[$atKey] ?? 0;
            if (is_array($cached) && (time() - (int)$cachedAt) < self::CACHE_TTL) {
                return $cached;
            }
        }

        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id, name, external_id FROM classes WHERE id=?');
        $st->execute([$classId]);
        $class = $st->fetch();
        if (!$class) {
            return ['className' => '', 'lessons' => [], 'errors' => ['Класс не найден.']];
        }

        $className = (string)$class['name'];
        $groupId = (int)($class['external_id'] ?? 0);
        if ($groupId <= 0) {
            return [
                'className' => $className,
                'lessons'   => [],
                'errors'    => ['У класса нет привязки к порталу (external_id) — прогресс на уроки недоступен.'],
            ];
        }

        // Все занятия класса: по каждому уроку показываем срез на его начало.
        $st = $pdo->prepare(
            "SELECT l.id, l.date, l.start_time, s.name AS subject,
                    (SELECT GROUP_CONCAT(COALESCE(NULLIF(TRIM(hw.title), ''), NULLIF(TRIM(hw.description), ''), 'Задание'), '; ')
                     FROM homeworks hw WHERE hw.lesson_id = l.id) AS homework
             FROM lessons l
             JOIN subjects s ON s.id = l.subject_id
             WHERE l.class_id = ?
             ORDER BY l.date ASC, COALESCE(NULLIF(TRIM(l.start_time), ''), '00:00') ASC, l.id ASC"
        );
        $st->execute([$classId]);
        $lessons = $st->fetchAll();

        $now = gmdate('Y-m-d H:i:s');
        $untils = [];
        foreach ($lessons as &$lesson) {
            $time = self::lessonTime((string)($lesson['start_time'] ?? ''));
            $until = self::localToUtc((string)$lesson['date'], $time);
            $lesson['display_time'] = $time;
            $lesson['until'] = $until;
            $lesson['future'] = $until === null || $until > $now;
            if (!$lesson['future']) {
                $untils[] = $until;
            }
        }
        unset($lesson);

        $summaries = $untils
            ? QuizProgressService::classSummaryBatch($groupId, $untils, $bypassCache, $server)
            : [];

        $errors = [];
        $rows = [];
        foreach ($lessons as $lesson) {
            $row = [
                'lesson_id'      => (int)$lesson['id'],
                'date'           => (string)$lesson['date'],
                'time'           => (string)$lesson['display_time'],
                'subject'        => (string)$lesson['subject'],
                'homework'       => (string)($lesson['homework'] ?? ''),
                'future'         => (bool)$lesson['future'],
                'quiz_passed'    => null,
                'quiz_attempted' => null,
                'tasks_solved'   => null,
                'tasks_total'    => null,
                'points'         => null,
            ];

            if ($lesson['future']) {
                $rows[] = $row;
                continue;
            }

            $until = (string)$lesson['until'];
            $summary = $summaries[$until] ?? null;
            if ($summary === null) {
                $errors[] = 'Не удалось получить прогресс на ' . $until . ' UTC.';
                $rows[] = $row;
                continue;
            }
            foreach (($summary['errors'] ?? []) as $err) {
                $errors[] = (string)$err;
            }

            $studentRow = null;
            foreach (($summary['students'] ?? []) as $stRow) {
                if ((int)($stRow['id'] ?? 0) === $externalId) {
                    $studentRow = $stRow;
                    break;
                }
            }

            $quizPassed = 0;
            $quizAttempted = 0;
            $tasksSolved = 0;
            $tasksTotal = 0;
            foreach (($studentRow['lessons'] ?? []) as $l) {
                if (($l['quiz'] ?? null) !== null) {
                    $quizAttempted++;
                    if ((int)$l['quiz'] >= 100) {
                        $quizPassed++;
                    }
                }
                $tasksSolved += (int)($l['solved'] ?? 0);
                $tasksTotal += (int)($l['total'] ?? 0);
            }

            $row['quiz_passed'] = $quizPassed;
            $row['quiz_attempted'] = $quizAttempted;
            $row['tasks_solved'] = $tasksSolved;
            $row['tasks_total'] = $tasksTotal;
            $row['points'] = $quizPassed + $tasksSolved;
            $rows[] = $row;
        }

        $result = [
            'className' => $className,
            'lessons'   => $rows,
            'errors'    => array_values(array_unique($errors)),
        ];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$cacheKey] = $result;
            $_SESSION[$atKey] = time();
        }

        return $result;
    }

    /**
     * Нормализует время начала урока из БД: "8:35" / "08:35:00" → "08:35".
     * Пустое или некорректное значение — "00:00".
     */
    private static function lessonTime(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{1,2}):([0-5]\d)/', $raw, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        return '00:00';
    }

    /**
     * Локальное время урока (как в расписании журнала) → UTC (Y-m-d H:i:s).
     */
    private static function localToUtc(string $date, string $time): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $dt = new DateTime(
                $date . ' ' . $time . ':00',
                new DateTimeZone((string)(config()['display_timezone'] ?? 'UTC'))
            );
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function summary(?int $studentId, bool $bypassCache): ?array
    {
        $quizRaw = self::myPythonProgress($bypassCache, $studentId);
        $contestRaw = self::myContestProgress($bypassCache, $studentId);
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

    private static function apiGet(string $url, string $cacheKey, bool $bypassCache = false, bool $server = false): ?array
    {
        if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
            $cached = $_SESSION['sp_' . $cacheKey] ?? null;
            $cachedAt = $_SESSION['sp_at_' . $cacheKey] ?? 0;
            if (is_array($cached) && (time() - $cachedAt) < self::CACHE_TTL) {
                return $cached;
            }
        }

        // Серверный вызов (карточка ребёнка у родителя): без куки, target
        // ученик передаётся параметром user_id, доверие — по IP на стороне API.
        $cookedCookie = '';
        if (!$server && !empty($_COOKIE['auth_session'])) {
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
