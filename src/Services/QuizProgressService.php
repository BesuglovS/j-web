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
     * @param string|null $until дата Y-m-d или точное время Y-m-d H:i:s
     */
    public static function pythonClassProgress(int $groupId, bool $bypassCache = false, ?string $until = null): ?array
    {
        $url = self::withUntil(
            config()['python_admin_url'] . '?action=class_progress&group_id=' . $groupId,
            $until
        );
        return self::apiGet($url, self::cacheKey('py_prog_' . $groupId, $until), $bypassCache);
    }

    /**
     * Прогресс по задачам-контестам для группы (из contest-web).
     * Возвращает ['students' => [{id, name, contests: {contest_id: {solved, total}}}...]] или null.
     * @param string|null $until дата Y-m-d или точное время Y-m-d H:i:s
     */
    public static function contestClassProgress(int $groupId, bool $bypassCache = false, ?string $until = null): ?array
    {
        $url = self::withUntil(config()['contest_admin_url'] . '&group_id=' . $groupId, $until);
        return self::apiGet($url, self::cacheKey('ct_prog_' . $groupId, $until), $bypassCache);
    }

    /**
     * Сводка по классу через сервер-к-сервер вызовы (без SSO-куки) — для тьютора:
     * j-web обращается к API соседних проектов со своего сервера (доверие — по IP,
     * ALLOWED_SERVER_IPS на стороне python-web/contest-web). Админ продолжает
     * использовать вызовы под своей сессией (classSummary()).
     */
    public static function classSummaryServer(int $groupId, bool $bypassCache = false, ?string $until = null): array
    {
        return self::classSummary($groupId, [
            'python'  => self::pythonClassProgressServer($groupId, $bypassCache, $until),
            'contest' => self::contestClassProgressServer($groupId, $bypassCache, $until),
        ], false, $until);
    }

    private static function pythonClassProgressServer(int $groupId, bool $bypassCache, ?string $until = null): ?array
    {
        return self::apiGet(
            self::withUntil(
                config()['python_admin_url'] . '?action=class_progress&group_id=' . $groupId,
                $until
            ),
            self::cacheKey('py_prog_s_' . $groupId, $until),
            $bypassCache,
            true
        );
    }

    private static function contestClassProgressServer(int $groupId, bool $bypassCache, ?string $until = null): ?array
    {
        return self::apiGet(
            self::withUntil(config()['contest_admin_url'] . '&group_id=' . $groupId, $until),
            self::cacheKey('ct_prog_s_' . $groupId, $until),
            $bypassCache,
            true
        );
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
     * @param string|null $until  дата Y-m-d или точное время Y-m-d H:i:s (null — текущий)
     */
    public static function classSummary(int $groupId, ?array $inject = null, bool $bypassCache = false, ?string $until = null): array
    {
        $lessonContests = config()['python_lesson_contests'];
        if ($inject !== null) {
            $quizRaw = $inject['python'] ?? null;
            $contestRaw = $inject['contest'] ?? null;
        } else {
            $quizRaw = self::pythonClassProgress($groupId, $bypassCache, $until);
            $contestRaw = self::contestClassProgress($groupId, $bypassCache, $until);
        }

        $errors = [];
        if ($quizRaw === null) {
            $errors[] = 'python-web: не удалось получить прогресс квизов';
        } elseif ($until !== null && (string)($quizRaw['until'] ?? '') !== $until) {
            // Старая версия API молча вернула бы текущие данные — не выдаём их
            // за исторические и просим обновить python-web.
            $quizRaw = null;
            $errors[] = 'python-web: API не поддерживает оценку прогресса на дату и время — обновите python-web';
        }
        if ($contestRaw === null) {
            $errors[] = 'contest-web: не удалось получить прогресс задач';
        } elseif ($until !== null && (string)($contestRaw['until'] ?? '') !== $until) {
            $contestRaw = null;
            $errors[] = 'contest-web: API не поддерживает оценку прогресса на дату и время — обновите contest-web';
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

    /**
     * Пакетный срез квизов python-web на несколько моментов.
     * Возвращает ответ batch-API ({snapshots: ...}) или null при ошибке.
     *
     * @param string[] $untils
     */
    private static function pythonClassProgressBatch(int $groupId, array $untils, bool $bypassCache = false, bool $server = false): ?array
    {
        $url = config()['python_admin_batch_url']
            . '&group_id=' . $groupId
            . '&untils=' . rawurlencode(implode('|', $untils));
        $key = 'py_batch_' . ($server ? 's_' : '') . $groupId . '_' . md5(implode('|', $untils));
        return self::apiGet($url, $key, $bypassCache, $server);
    }

    /**
     * Пакетный срез задач contest-web на несколько моментов.
     * Возвращает ответ batch-API ({snapshots: ...}) или null при ошибке.
     *
     * @param string[] $untils
     */
    private static function contestClassProgressBatch(int $groupId, array $untils, bool $bypassCache = false, bool $server = false): ?array
    {
        $url = config()['contest_admin_batch_url']
            . '&group_id=' . $groupId
            . '&untils=' . rawurlencode(implode('|', $untils));
        $key = 'ct_batch_' . ($server ? 's_' : '') . $groupId . '_' . md5(implode('|', $untils));
        return self::apiGet($url, $key, $bypassCache, $server);
    }

    /**
     * Пакетная загрузка сводок класса на несколько моментов времени.
     *
     * Для карточки ученика нужен прогресс «на начало каждого урока», то есть
     * отдельный срез на каждую дату/время. Сначала пробуем пакетные API
     * python-web/contest-web (один запрос на сервис), а при их недоступности
     * откатываемся к параллельным одиночным запросам через curl_multi.
     *
     * @param string[] $untils моменты в UTC (Y-m-d H:i:s)
     * @return array<string,array> until => результат classSummary()
     */
    public static function classSummaryBatch(int $groupId, array $untils, bool $bypassCache = false, bool $server = false): array
    {
        $untils = array_values(array_unique(array_filter(
            array_map(static fn($u): string => (string)$u, $untils),
            static fn(string $u): bool => $u !== ''
        )));
        if (!$untils) {
            return [];
        }

        // Быстрый путь: пакетные срезы на стороне python-web/contest-web.
        // До 60 моментов на запрос — с запасом к лимиту API и длине URL.
        $pySnapshots = [];
        $ctSnapshots = [];
        $batchOk = true;
        foreach (array_chunk($untils, 60) as $chunk) {
            $pyBatch = self::pythonClassProgressBatch($groupId, $chunk, $bypassCache, $server);
            $ctBatch = self::contestClassProgressBatch($groupId, $chunk, $bypassCache, $server);
            if (!is_array($pyBatch) || !isset($pyBatch['snapshots']) || !is_array($pyBatch['snapshots'])
                || !is_array($ctBatch) || !isset($ctBatch['snapshots']) || !is_array($ctBatch['snapshots'])) {
                $batchOk = false;
                break;
            }
            $pySnapshots += $pyBatch['snapshots'];
            $ctSnapshots += $ctBatch['snapshots'];
        }
        if ($batchOk) {
            $out = [];
            foreach ($untils as $until) {
                $out[$until] = self::classSummary($groupId, [
                    'python'  => $pySnapshots[$until] ?? null,
                    'contest' => $ctSnapshots[$until] ?? null,
                ], false, $until);
            }
            return $out;
        }

        $raw = ['python' => [], 'contest' => []];
        $urls = [];
        $meta = [];

        foreach ($untils as $until) {
            foreach (['python', 'contest'] as $src) {
                $cacheBase = ($src === 'python'
                        ? ($server ? 'py_prog_s_' : 'py_prog_')
                        : ($server ? 'ct_prog_s_' : 'ct_prog_')) . $groupId;
                $cacheKey = self::cacheKey($cacheBase, $until);
                $sessionKey = 'qp_' . $cacheKey;
                $atKey = 'qp_at_' . $cacheKey;

                if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
                    $cached = $_SESSION[$sessionKey] ?? null;
                    $cachedAt = $_SESSION[$atKey] ?? 0;
                    if (is_array($cached) && (time() - (int)$cachedAt) < self::CACHE_TTL) {
                        $raw[$src][$until] = $cached;
                        continue;
                    }
                }

                $url = $src === 'python'
                    ? self::withUntil(config()['python_admin_url'] . '?action=class_progress&group_id=' . $groupId, $until)
                    : self::withUntil(config()['contest_admin_url'] . '&group_id=' . $groupId, $until);

                $key = $src . '|' . $until;
                $urls[$key] = $url;
                $meta[$key] = ['src' => $src, 'until' => $until, 'cacheKey' => $cacheKey];
            }
        }

        if ($urls) {
            $fetched = self::apiGetBatch($urls, $server);
            foreach ($fetched as $key => $data) {
                $m = $meta[$key] ?? null;
                if ($m === null) {
                    continue;
                }
                $raw[$m['src']][$m['until']] = $data;
                if ($data !== null && session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['qp_' . $m['cacheKey']] = $data;
                    $_SESSION['qp_at_' . $m['cacheKey']] = time();
                }
            }
        }

        $out = [];
        foreach ($untils as $until) {
            $out[$until] = self::classSummary($groupId, [
                'python'  => $raw['python'][$until] ?? null,
                'contest' => $raw['contest'][$until] ?? null,
            ], false, $until);
        }
        return $out;
    }

    /**
     * Параллельно загружает набор URL и возвращает декодированные JSON-ответы.
     * Ошибки/не-200/timeout дают null (как apiGet()).
     *
     * @param array<string,string> $urls ключ => URL
     * @return array<string,array|null>
     */
    private static function apiGetBatch(array $urls, bool $server = false): array
    {
        $result = array_fill_keys(array_keys($urls), null);
        if (!$urls) {
            return $result;
        }

        $cookedCookie = '';
        if (!$server && !empty($_COOKIE['auth_session'])) {
            $safe = preg_replace('/[^A-Za-z0-9,_\-]/', '', (string) $_COOKIE['auth_session']);
            if ($safe !== '') {
                $cookedCookie = 'auth_session=' . $safe;
            }
        }

        $mh = curl_multi_init();
        if ($mh === false) {
            return $result;
        }

        $queue = $urls;
        $active = [];
        $concurrency = 8;
        $deadline = microtime(true) + 15.0;

        while ($queue || $active) {
            if (microtime(true) >= $deadline) {
                break;
            }
            while (count($active) < $concurrency && $queue) {
                $key = array_key_first($queue);
                $url = $queue[$key];
                unset($queue[$key]);

                $ch = curl_init($url);
                if ($ch === false) {
                    continue;
                }
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
                curl_multi_add_handle($mh, $ch);
                $active[spl_object_id($ch)] = ['ch' => $ch, 'key' => $key];
            }

            if (!$active) {
                break;
            }

            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($status !== CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
                break;
            }

            if ($running && curl_multi_select($mh, 1.0) === -1) {
                usleep(1000);
            }

            $processed = 0;
            while (($info = curl_multi_info_read($mh)) !== false) {
                $processed++;
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                $entry = $active[$id] ?? null;
                if ($entry === null) {
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    continue;
                }
                unset($active[$id]);

                $response = curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($response !== false && $httpCode === 200) {
                    $data = json_decode((string)$response, true);
                    if (is_array($data)) {
                        $result[$entry['key']] = $data;
                    }
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            if (!$running && !$queue && $active && $processed === 0) {
                break;
            }
        }

        // При аварийном выходе из цикла корректно закрыть оставшиеся handles.
        foreach ($active as $entry) {
            curl_multi_remove_handle($mh, $entry['ch']);
            curl_close($entry['ch']);
        }
        curl_multi_close($mh);

        return $result;
    }

    /**
     * Добавляет к URL фильтр даты (until=Y-m-d), если он задан.
     */
    private static function withUntil(string $url, ?string $until): string
    {
        if ($until === null || $until === '') {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'until=' . rawurlencode($until);
    }

    /**
     * Ключ кэша в сессии: раздельные записи для текущих данных и каждой даты.
     */
    private static function cacheKey(string $base, ?string $until): string
    {
        return $until !== null && $until !== '' ? $base . '_on_' . $until : $base;
    }

    private static function apiGet(string $url, string $cacheKey, bool $bypassCache = false, bool $server = false): ?array
    {
        if (!$bypassCache && session_status() === PHP_SESSION_ACTIVE) {
            $cached = $_SESSION['qp_' . $cacheKey] ?? null;
            $cachedAt = $_SESSION['qp_at_' . $cacheKey] ?? 0;
            if (is_array($cached) && (time() - $cachedAt) < self::CACHE_TTL) {
                return $cached;
            }
        }

        // Серверный вызов (кабинет тьютора): без куки, доверие — по IP на
        // стороне API соседних проектов.
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
            $_SESSION['qp_' . $cacheKey] = $data;
            $_SESSION['qp_at_' . $cacheKey] = time();
        }
        return $data;
    }
}
