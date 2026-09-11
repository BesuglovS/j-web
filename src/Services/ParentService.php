<?php

/**
 * Синхронизация родителей с единым порталом auth-web.
 *
 * Родители больше не создаются и не редактируются в журнале: данные
 * (профили + связи «родитель–ребёнок») ведутся в auth-web, а журнал
 * держит локальное read-only зеркало: users (role=parent, паролей нет —
 * вход через SSO), таблица parents (external_id = id профиля parents
 * auth-web) и связи student_parent (через students.external_id =
 * id ученика auth-web). Один родитель может быть привязан к нескольким
 * детям, у ребёнка может быть несколько родителей.
 */
class ParentService
{
    /** TTL кеша авто-синка (секунды) */
    private const SYNC_TTL = 300;

    /**
     * Запрашивает родителей у auth-web.
     * Возвращает список [{id, user_id, login, last_name, first_name,
     * middle_name, children:[id,...]}] либо null.
     */
    public static function fetchFromAuth(): ?array
    {
        $url = config()['auth_parents_url'] ?? '';
        if ($url === '') {
            return null;
        }
        $data = self::fetchJson($url);
        if (!is_array($data) || !isset($data['parents']) || !is_array($data['parents'])) {
            return null;
        }
        return $data['parents'];
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
     * Полная синхронизация: переносит родителей auth-web в локальные
     * users (role=parent) и parents (external_id = id профиля auth-web),
     * пересобирает student_parent по students.external_id и удаляет
     * локальные записи, которых больше нет в auth-web.
     * Возвращает отчёт.
     */
    public static function sync(): array
    {
        $report = ['ok' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => []];
        $parents = static::fetchFromAuth();
        if ($parents === null) {
            $report['errors'][] = 'Не удалось получить список родителей от auth-web (public_parents.php).';
            return $report;
        }

        $pdo = Database::pdo();

        // дети из auth-web: external_id ученика -> локальный id студента
        $studentIds = [];
        $st = $pdo->query('SELECT id, external_id FROM students WHERE external_id IS NOT NULL');
        foreach ($st->fetchAll() as $s) {
            $studentIds[(int)$s['external_id']] = (int)$s['id'];
        }

        // локальные users (role=parent): login -> id
        $parentUsers = [];
        $st = $pdo->query("SELECT id, login FROM users WHERE role='parent'");
        foreach ($st->fetchAll() as $u) {
            $parentUsers[strtolower(trim($u['login']))] = (int)$u['id'];
        }

        // локальные parents: external_id -> id, а также логин учётки -> id
        $existingParents = [];
        $parentsByLogin = [];
        $st = $pdo->query('SELECT p.id, p.external_id, u.login FROM parents p LEFT JOIN users u ON u.id=p.user_id');
        foreach ($st->fetchAll() as $p) {
            if ($p['external_id'] !== null) {
                $existingParents[(int)$p['external_id']] = (int)$p['id'];
            }
            $l = strtolower(trim((string)$p['login']));
            if ($l !== '') {
                $parentsByLogin[$l] = (int)$p['id'];
            }
        }

        $updUser = $pdo->prepare('UPDATE users SET full_name=? WHERE id=?');
        $insUser = $pdo->prepare("INSERT INTO users (login, password_hash, role, full_name) VALUES (?,?,?,?)");
        $insParent = $pdo->prepare('INSERT INTO parents (user_id, last_name, first_name, middle_name, external_id) VALUES (?,?,?,?,?)');
        $updParent = $pdo->prepare('UPDATE parents SET user_id=?, last_name=?, first_name=?, middle_name=?, external_id=? WHERE id=?');
        $insLink = $pdo->prepare('INSERT OR IGNORE INTO student_parent (student_id, parent_id) VALUES (?,?)');
        $delLink = $pdo->prepare('DELETE FROM student_parent WHERE parent_id=? AND student_id=?');

        $syncedParentIds = [];
        $syncedLogins = [];

        foreach ($parents as $p) {
            $extId = (int)($p['id'] ?? 0);
            $login = trim((string)($p['login'] ?? ''));
            $last = trim((string)($p['last_name'] ?? ''));
            $first = trim((string)($p['first_name'] ?? ''));
            $middle = trim((string)($p['middle_name'] ?? ''));
            $fullName = trim(implode(' ', array_filter([$last, $first, $middle])));
            if ($extId <= 0 || $login === '') {
                continue;
            }
            $loginKey = strtolower($login);
            $syncedLogins[$loginKey] = true;

            // локальная учётка (пароля нет: вход через SSO)
            $localUserId = $parentUsers[$loginKey] ?? null;
            if ($localUserId === null) {
                $insUser->execute([$login, '', 'parent', $fullName]);
                $localUserId = (int)$pdo->lastInsertId();
                $parentUsers[$loginKey] = $localUserId;
            } else {
                $updUser->execute([$fullName, $localUserId]);
            }

            // профиль parents: по external_id, затем по логину учётки (бэкфилл)
            $parentId = $existingParents[$extId] ?? ($parentsByLogin[$loginKey] ?? null);
            if ($parentId === null) {
                $insParent->execute([$localUserId, $last, $first, $middle, $extId]);
                $parentId = (int)$pdo->lastInsertId();
                $existingParents[$extId] = $parentId;
                $report['added']++;
            } else {
                $updParent->execute([$localUserId, $last, $first, $middle, $extId, $parentId]);
                $existingParents[$extId] = $parentId;
                $report['updated']++;
            }
            $syncedParentIds[$parentId] = true;
            $report['ok']++;

            // student_parent: пересборка связей для этого родителя
            $current = [];
            $st = $pdo->prepare('SELECT student_id FROM student_parent WHERE parent_id=?');
            $st->execute([$parentId]);
            foreach ($st->fetchAll() as $row) {
                $current[(int)$row['student_id']] = true;
            }
            $desired = [];
            foreach (($p['children'] ?? []) as $childExt) {
                $sid = $studentIds[(int)$childExt] ?? null;
                if ($sid !== null) {
                    $desired[$sid] = true;
                }
            }
            foreach (array_keys($current) as $sid) {
                if (!isset($desired[$sid])) {
                    $delLink->execute([$parentId, $sid]);
                }
            }
            foreach (array_keys($desired) as $sid) {
                if (!isset($current[$sid])) {
                    $insLink->execute([$sid, $parentId]);
                }
            }
        }

        // Родители, которых больше нет в auth-web, удаляются из зеркала
        // (связи student_parent удаляются каскадом). Учётные записи
        // родителей паролей не хранят — их тоже убираем.
        $delParent = $pdo->prepare('DELETE FROM parents WHERE id=?');
        $delParentLinks = $pdo->prepare('DELETE FROM student_parent WHERE parent_id=?');
        $allParents = $pdo->query('SELECT id FROM parents')->fetchAll();
        foreach ($allParents as $row) {
            if (!isset($syncedParentIds[(int)$row['id']])) {
                $delParentLinks->execute([(int)$row['id']]);
                $delParent->execute([(int)$row['id']]);
                $report['removed']++;
            }
        }
        $delUser = $pdo->prepare("DELETE FROM users WHERE role='parent' AND lower(login)=?");
        foreach ($parentUsers as $l => $uid) {
            if (!isset($syncedLogins[$l])) {
                $delUser->execute([$l]);
            }
        }

        $_SESSION['parents_synced_at'] = time();
        return $report;
    }

    /**
     * Авто-синхронизация перед отображением данных о родителях,
     * если с прошлого раза прошло больше SYNC_TTL. Ошибки не бросает.
     */
    public static function ensureSynced(): void
    {
        if (isset($_SESSION['parents_synced_at']) && (time() - (int)$_SESSION['parents_synced_at']) < self::SYNC_TTL) {
            return;
        }
        self::sync();
    }
}
