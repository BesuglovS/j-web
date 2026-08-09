<?php

/**
 * Синхронизация списка студентов с единого портала auth-web.
 *
 * Студенты больше не создаются и не редактируются в журнале: состав
 * изменяется в auth-web (пользователи + принадлежность к группам).
 * Журнал держит локальное зеркало записей (users role=student + таблица
 * students), которое обновляется из открытых API auth-web по внешнему
 * идентификатору external_id (id пользователя auth-web).
 */
class StudentService
{
    /** TTL кеша авто-синка (секунды) */
    private const SYNC_TTL = 300;

    /**
     * Запрашивает пользователей у auth-web.
     * Возвращает список [{id,login,display_name,is_admin}] либо null.
     */
    public static function fetchUsersFromAuth(): ?array
    {
        $url = config()['auth_users_url'] ?? '';
        if ($url === '') {
            return null;
        }
        $data = self::fetchJson($url);
        if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
            return null;
        }
        return $data['users'];
    }

    /**
     * Запрашивает принадлежность пользователей к группам у auth-web.
     * Возвращает [{user_id,group_id}, ...] либо null.
     */
    public static function fetchMembershipsFromAuth(): ?array
    {
        $url = config()['auth_memberships_url'] ?? '';
        if ($url === '') {
            return null;
        }
        $data = self::fetchJson($url);
        if (!is_array($data) || !isset($data['memberships']) || !is_array($data['memberships'])) {
            return null;
        }
        return $data['memberships'];
    }

    private static function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Полная синхронизация: берёт пользователей auth-web, которые состоят
     * хотя бы в одной группе, и переносит их в локальные users (role=student)
     * и students (external_id = id пользователя auth-web).
     * Удаление строк НЕ выполняется (безопасно для занятий/оценок).
     * Возвращает отчёт.
     */
    public static function sync(): array
    {
        $report = ['ok' => 0, 'added' => 0, 'updated' => 0, 'errors' => []];
        $users = static::fetchUsersFromAuth();
        $memberships = static::fetchMembershipsFromAuth();
        if ($users === null || $memberships === null) {
            $report['errors'][] = 'Не удалось получить список пользователей от auth-web (public_users.php / user_groups.php).';
            return $report;
        }

        $pdo = Database::pdo();

        // классы тоже подтягиваем из auth-web заранее (нужен external_id)
        GroupService::ensureSynced();

        // id группы auth-web -> локальный id класса
        $classes = [];
        $st = $pdo->query('SELECT id, external_id FROM classes WHERE external_id IS NOT NULL');
        foreach ($st->fetchAll() as $c) {
            $classes[(int)$c['external_id']] = (int)$c['id'];
        }

        // пользователи auth-web, состоящие хотя бы в одной группе -> класс
        $studentClass = [];
        foreach ($memberships as $m) {
            $uid = (int)($m['user_id'] ?? 0);
            $gid = (int)($m['group_id'] ?? 0);
            if (isset($classes[$gid]) && !isset($studentClass[$uid])) {
                $studentClass[$uid] = $classes[$gid];
            }
        }

        // локальные users: login -> id
        $existingUsers = [];
        $st = $pdo->query("SELECT id, login FROM users WHERE role='student'");
        foreach ($st->fetchAll() as $u) {
            $existingUsers[strtolower(trim($u['login']))] = (int)$u['id'];
        }

        // существующие students: external_id -> id, а также login -> id
        // (для первичного связывания старых записей по логину)
        $existingStudents = [];
        $studentsByLogin = [];
        $st = $pdo->query('SELECT s.id, s.external_id, u.login FROM students s LEFT JOIN users u ON u.id=s.user_id');
        foreach ($st->fetchAll() as $s) {
            if ($s['external_id'] !== null) {
                $existingStudents[(int)$s['external_id']] = (int)$s['id'];
            }
            $l = strtolower(trim((string)$s['login']));
            if ($l !== '') {
                $studentsByLogin[$l] = (int)$s['id'];
            }
        }

        $updUser = $pdo->prepare('UPDATE users SET full_name=? WHERE id=?');
        $insUser = $pdo->prepare('INSERT INTO users (login, password_hash, role, full_name) VALUES (?,?,?,?)');
        $updStudent = $pdo->prepare('UPDATE students SET class_id=?, last_name=?, first_name=?, middle_name=?, user_id=? WHERE id=?');
        $insStudent = $pdo->prepare('INSERT INTO students (user_id, class_id, last_name, first_name, middle_name, external_id) VALUES (?,?,?,?,?,?)');

        foreach ($users as $u) {
            $extId = (int)($u['id'] ?? 0);
            $login = trim((string)($u['login'] ?? ''));
            if ($extId <= 0 || $login === '' || !empty($u['is_admin'])) {
                continue;
            }
            $classId = $studentClass[$extId] ?? null;
            if (!$classId) {
                continue; // не ученик ни одной группы — не студент журнала
            }

            $names = self::splitName((string)($u['display_name'] ?? ''));
            $loginKey = strtolower($login);

            // локальный users (role=student)
            $localUserId = $existingUsers[$loginKey] ?? null;
            if ($localUserId === null) {
                $insUser->execute([$login, '', 'student', $names['full']]);
                $localUserId = (int)$pdo->lastInsertId();
                $existingUsers[$loginKey] = $localUserId;
            } else {
                $updUser->execute([$names['full'], $localUserId]);
            }

            // students.u
            if (isset($existingStudents[$extId])) {
                $updStudent->execute([$classId, $names['last'], $names['first'], $names['middle'], $localUserId, $existingStudents[$extId]]);
                $report['updated']++;
            } elseif (isset($studentsByLogin[$loginKey])) {
                // старая запись без external_id — привязываем и обновляем
                $updStudent->execute([$classId, $names['last'], $names['first'], $names['middle'], $localUserId, $studentsByLogin[$loginKey]]);
                $pdo->prepare('UPDATE students SET external_id=? WHERE id=?')->execute([$extId, $studentsByLogin[$loginKey]]);
                $existingStudents[$extId] = $studentsByLogin[$loginKey];
                $report['updated']++;
            } else {
                $insStudent->execute([$localUserId, $classId, $names['last'], $names['first'], $names['middle'], $extId]);
                $existingStudents[$extId] = (int)$pdo->lastInsertId();
                $report['added']++;
            }
            $report['ok']++;
        }

        $_SESSION['students_synced_at'] = time();
        return $report;
    }

    /**
     * Авто-синхронизация перед отображением списков студентов,
     * если с прошлого раза прошло больше SYNC_TTL. Ошибки не бросает.
     */
    public static function ensureSynced(): void
    {
        if (isset($_SESSION['students_synced_at']) && (time() - (int)$_SESSION['students_synced_at']) < self::SYNC_TTL) {
            return;
        }
        self::sync();
    }

    /**
     * Разбивает display_name на last/first/middle по пробелам.
     *
     * В auth-web отображаемое имя может содержать класс в скобках
     * в конце строки, например «Иванов Иван (11 А)». Такое включение
     * обрезаем: название класса журнал определяет отдельно по группе.
     */
    public static function splitName(string $displayName): array
    {
        $displayName = trim($displayName);
        $displayName = (string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $displayName);
        $displayName = trim($displayName);

        $parts = preg_split('/\s+/u', $displayName);
        $parts = $parts ?: [];
        $last = array_shift($parts) ?? '';
        $first = array_shift($parts) ?? '';
        $middle = array_shift($parts) ?? '';
        // остаток (4+ слов) кладём в first_name
        if ($parts) {
            $first = trim($first . ($first ? ' ' : '') . implode(' ', $parts));
        }
        return [
            'last'   => $last,
            'first'  => $first,
            'middle' => $middle,
            'full'   => trim(implode(' ', array_filter([$last, $first, $middle]))),
        ];
    }
}