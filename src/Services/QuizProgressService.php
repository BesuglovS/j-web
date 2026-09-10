<?php
declare(strict_types=1);

/**
 * Прогресс студентов по python-курсу: квизы уроков (python-web) и задачи-контесты (contest-web).
 * Данные берутся по HTTP от серверных API соседних проектов; сессия админа
 * проксируется через куку auth_session (как в AuthClient).
 * Ответы кэшируются в сессии (TTL 5 минут), чтобы не нагружать чужие БД.
 */
class QuizProgressService
{
    private const CACHE_TTL = 300;
    private const MAX_LESSONS = 50;

    /**
     * Прогресс квизов по группе (из python-web sandbox/admin_quiz.php).
     * Возвращает ['students' => [{id, name, lessons: {num: {completed, quiz_score}}}...],
     *             'max_lesson_with_quizzes' => int] или null при ошибке.
     */
    public static function pythonClassProgress(int $groupId, bool $bypassCache = false): ?array
    {
        return self::apiGet(config()['python_admin_url'] . '?action=class_progress&group_id=' . $groupId, 'py_prog_' . $groupId, $bypassCache);
    }

    /**
     * Прогресс по задачам-контестам для группы (из contest-web).
     * Возвращает ['students' => [{id, name, contests: {contest_id: {solved, total}}}...]] или null.
     */
    public static function contestClassProgress(int $groupId, bool $bypassCache = false): ?array
    {
        return self::apiGet(config()['contest_admin_url'] . '&group_id=' . $groupId, 'ct_prog_' . $groupId, $bypassCache);
    }

    /**
     * Сводка по классу: объединяет квизы и решённые задачи урока.
     * Возвращает [
     *   'students'    => [{id, name, lessons: {num: ['quiz' => int|null, 'solved' => int, 'total' => int]}}...],
     *   'max_lesson'  => int,      // последний урок с данными (0 — данных нет)
     *   'has_final'   => bool,     // есть ли результаты итогового теста (урок -1)
     *   'errors'      => string[]  // какие источники не ответили
     * ].
    /**
     * @param array|null $inject  для тестов: ['python' => raw, 'contest' => raw] — вместо HTTP
     * @param bool $bypassCache  игнорировать кэш: получить свежие данные (обновить и кэш)
     */
    public static function classSummary(int $groupId, ?array $inject = null, bool $bypassCache = false): array
    {
        $lessonContests = config()['python_lesson_contests'];
        $quizRaw = $inject['python'] ?? self::pythonClassProgress($groupId, $bypassCache);
        $contestRaw = $inject['contest'] ?? self::contestClassProgress($groupId, $bypassCache);

        $errors = [];
        if ($quizRaw === null) {
            $errors[] = 'python-web: не удалось получить прогресс квизов';
        }
        if ($contestRaw === null) {
            $errors[] = 'contest-web: не удалось получить прогресс задач';
        }

        $byUser = [];
        foreach ($quizRaw['students'] ?? [] as $s) {
            $uid = (int) $s['id'];
            $byUser[$uid] = ['id' => $uid, 'name' => (string) $s['name'], 'lessons' => []];
            foreach (($s['lessons'] ?? []) as $num => $l) {
                $num = (int) $num;
                if ($num === -1) {
                    continue;
                }
                if ($num < 1 || $num > self::MAX_LESSONS) {
                    continue;
                }
                $byUser[$uid]['lessons'][$num] = [
                    'quiz'   => $l['quiz_score'] !== null ? (int) $l['quiz_score'] : null,
                    'solved' => 0,
                    'total'  => isset($lessonContests[$num]) ? 0 : null,
                ];
            }
        }

        // имена пользователей занести даже для тех, у кого нет квизов (контесты есть)
        if ($quizRaw === null) {
            foreach ($contestRaw['students'] ?? [] as $s) {
                $uid = (int) $s['id'];
                $byUser[$uid] = ['id' => $uid, 'name' => (string) $s['name'], 'lessons' => []];
            }
        }

        foreach ($contestRaw['students'] ?? [] as $s) {
            $uid = (int) $s['id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = ['id' => $uid, 'name' => (string) $s['name'], 'lessons' => []];
            }
            foreach (($s['contests'] ?? []) as $cid => $c) {
                $num = array_search((int) $cid, $lessonContests, true);
                if ($num === false) {
                    continue;
                }
                if (!isset($byUser[$uid]['lessons'][$num])) {
                    $byUser[$uid]['lessons'][$num] = ['quiz' => null, 'solved' => 0, 'total' => 0];
                }
                $byUser[$uid]['lessons'][$num]['solved'] = (int) $c['solved'];
                $byUser[$uid]['lessons'][$num]['total']  = (int) $c['total'];
            }
        }

        $maxLesson = 0;
        $hasFinal = false;
        foreach ($byUser as &$s) {
            ksort($s['lessons']);
            foreach ($s['lessons'] as $num => $l) {
                // «есть результат» = сдан квиз или решена хотя бы одна задача контеста
                if ($num > 0 && ($l['quiz'] !== null || (int)($l['solved'] ?? 0) > 0) && $num > $maxLesson) {
                    $maxLesson = $num;
                }
            }
        }
        unset($s);
        if ($quizRaw !== null) {
            foreach ($quizRaw['students'] ?? [] as $s) {
                if (isset($s['lessons'][-1])) {
                    $hasFinal = true;
                }
            }
        }

        usort($byUser, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'students'   => array_values($byUser),
            'max_lesson' => $maxLesson,
            'has_final'  => $hasFinal,
            'errors'     => $errors,
        ];
    }

    private static function apiGet(string $url, string $cacheKey, bool $bypassCache = false): ?array
    {
        if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
            $cached = $_SESSION['qp_' . $cacheKey] ?? null;
            $cachedAt = $_SESSION['qp_at_' . $cacheKey] ?? 0;
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
            $_SESSION['qp_' . $cacheKey] = $data;
            $_SESSION['qp_at_' . $cacheKey] = time();
        }
        return $data;
    }
}
