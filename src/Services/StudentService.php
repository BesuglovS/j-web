<?php

/**
 * Синхронизация списка студентов с единого портала auth-web.
 *
 * Студенты больше не создаются и не редактируются в журнале: состав
 * изменяется в auth-web (пользователи + принадлежность к группам).
 * Журнал держит локальное зеркало записей (users role=student + таблица
 * students), которое обновляется из открытых API auth-web по внешнему
 * идентификатору external_id (id пользователя auth-web).
 *
 * Один студент может состоять в нескольких группах (класс + подгруппы):
 * students.class_id = первичный класс, student_classes = все классы.
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
     * students.class_id = первичный класс, student_classes = все классы.
     * Возвращает отчёт.
     */
    public static function sync(): array
    {
        $report = ['ok' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0, 'deactivated' => 0, 'errors' => []];
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

        // пользователи auth-web -> все локальные class_id (много групп)
        $studentClasses = [];
        foreach ($memberships as $m) {
            $uid = (int)($m['user_id'] ?? 0);
            $gid = (int)($m['group_id'] ?? 0);
            if (isset($classes[$gid])) {
                $studentClasses[$uid][$classes[$gid]] = true;
            }
        }
        foreach ($studentClasses as &$arr) {
            $arr = array_keys($arr);
        }
        unset($arr);

        // локальные users: login -> id
        $existingUsers = [];
        $st = $pdo->query("SELECT id, login FROM users WHERE role='student'");
        foreach ($st->fetchAll() as $u) {
            $existingUsers[strtolower(trim($u['login']))] = (int)$u['id'];
        }

        // существующие students: external_id -> id, а также login -> id
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
        $updStudent = $pdo->prepare('UPDATE students SET class_id=?, last_name=?, first_name=?, middle_name=?, user_id=?, is_active=1 WHERE id=?');
        $insStudent = $pdo->prepare('INSERT INTO students (user_id, class_id, last_name, first_name, middle_name, external_id, is_active) VALUES (?,?,?,?,?,?,1)');
        $insSC = $pdo->prepare('INSERT OR IGNORE INTO student_classes (student_id, class_id) VALUES (?,?)');
        $delSC = $pdo->prepare('DELETE FROM student_classes WHERE student_id=?');

        foreach ($users as $u) {
            $extId = (int)($u['id'] ?? 0);
            $login = trim((string)($u['login'] ?? ''));
            if ($extId <= 0 || $login === '' || !empty($u['is_admin'])) {
                continue;
            }
            $classIds = $studentClasses[$extId] ?? [];
            if (!$classIds) {
                continue;
            }
            $primaryClassId = $classIds[0];

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

            // students
            $studentId = null;
            if (isset($existingStudents[$extId])) {
                $studentId = $existingStudents[$extId];
                $updStudent->execute([$primaryClassId, $names['last'], $names['first'], $names['middle'], $localUserId, $studentId]);
                $report['updated']++;
            } elseif (isset($studentsByLogin[$loginKey])) {
                $studentId = $studentsByLogin[$loginKey];
                $updStudent->execute([$primaryClassId, $names['last'], $names['first'], $names['middle'], $localUserId, $studentId]);
                $pdo->prepare('UPDATE students SET external_id=? WHERE id=?')->execute([$extId, $studentId]);
                $existingStudents[$extId] = $studentId;
                $report['updated']++;
            } else {
                $insStudent->execute([$localUserId, $primaryClassId, $names['last'], $names['first'], $names['middle'], $extId]);
                $studentId = (int)$pdo->lastInsertId();
                $existingStudents[$extId] = $studentId;
                $report['added']++;
            }
            $report['ok']++;

            // student_classes: перезаписываем все классы для этого студента
            $delSC->execute([$studentId]);
            foreach ($classIds as $cid) {
                $insSC->execute([$studentId, $cid]);
            }
        }

        // Ученики, больше не состоящие ни в одной группе auth-web, — кандидаты
        // на удаление. Удаляем при отсутствии связанных данных; иначе is_active=0.
        $activeExtIds = array_keys($studentClasses);
        $allStudents = $pdo->query('SELECT id, external_id FROM students')->fetchAll();
        $delStudent = $pdo->prepare('DELETE FROM students WHERE id=?');
        $deactStudent = $pdo->prepare('UPDATE students SET is_active=0 WHERE id=?');
        $delSCbyStudent = $pdo->prepare('DELETE FROM student_classes WHERE student_id=?');
        $cntLinked = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM marks WHERE student_id=?)'
            . ' + (SELECT COUNT(*) FROM homework_submissions WHERE student_id=?)'
            . ' + (SELECT COUNT(*) FROM lesson_remarks WHERE student_id=?)'
            . ' + (SELECT COUNT(*) FROM student_parent WHERE student_id=?) AS cnt'
        );
        foreach ($allStudents as $row) {
            $ext = $row['external_id'] === null ? null : (int)$row['external_id'];
            if ($ext !== null && in_array($ext, $activeExtIds, true)) {
                continue;
            }
            $cntLinked->execute([$row['id'], $row['id'], $row['id'], $row['id']]);
            $linked = (int)($cntLinked->fetchColumn() ?? 0);
            if ($linked === 0) {
                $delSCbyStudent->execute([$row['id']]);
                $delStudent->execute([$row['id']]);
                $report['removed']++;
            } else {
                $deactStudent->execute([$row['id']]);
                $delSCbyStudent->execute([$row['id']]);
                $report['deactivated']++;
            }
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
