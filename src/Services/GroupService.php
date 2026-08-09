<?php

/**
 * Синхронизация классов (групп) с единого портала auth-web.
 *
 * Классы больше не редактируются в журнале: создание/изменение/удаление
 * выполняется в auth-web (админ-раздел «Группы»). Журнал держит локальное
 * зеркало таблицы classes, которое обновляется из auth-web api/groups.php
 * по внешнему идентификатору external_id.
 */
class GroupService
{
    /** TTL кеша авто-синка (секунды) */
    private const SYNC_TTL = 300;

    /**
     * Запрашивает группы у auth-web.
     * Возвращает список [{id,name,description,user_count}] либо null при ошибке.
     */
    public static function fetchFromAuth(): ?array
    {
        $url = config()['auth_groups_url'] ?? '';
        if ($url === '') {
            return null;
        }
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
        if (!is_array($data) || !isset($data['groups']) || !is_array($data['groups'])) {
            return null;
        }
        return $data['groups'];
    }

    /**
     * Полная синхронизация: upsert классов из auth-web по external_id.
     * Строки, отсутствующие в auth-web, НЕ удаляются (безопасно для
     * существующих занятий/оценок). Возвращает отчёт.
     */
    public static function sync(): array
    {
        $report = ['ok' => 0, 'added' => 0, 'updated' => 0, 'errors' => []];
        $groups = self::fetchFromAuth();
        if ($groups === null) {
            $report['errors'][] = 'Не удалось получить список классов от auth-web (api/groups.php).';
            return $report;
        }

        $pdo = Database::pdo();
        $existing = [];
        $st = $pdo->query('SELECT id, external_id FROM classes WHERE external_id IS NOT NULL');
        foreach ($st as $r) {
            $existing[(int)$r['external_id']] = (int)$r['id'];
        }

        $upd = $pdo->prepare('UPDATE classes SET name=?, grade=?, school_year=? WHERE id=?');
        $ins = $pdo->prepare('INSERT INTO classes (name, grade, school_year, external_id) VALUES (?,?,?,?)');

        foreach ($groups as $g) {
            $extId = (int)($g['id'] ?? 0);
            $name = trim((string)($g['name'] ?? ''));
            if ($extId <= 0 || $name === '') {
                continue;
            }
            $grade = self::parseGrade($name);
            $year = trim((string)($g['description'] ?? '')) ?: null;

            if (isset($existing[$extId])) {
                $upd->execute([$name, $grade, $year, $existing[$extId]]);
                $report['updated']++;
            } else {
                $ins->execute([$name, $grade, $year, $extId]);
                $report['added']++;
            }
            $report['ok']++;
        }

        $_SESSION['classes_synced_at'] = time();
        return $report;
    }

    /**
     * Авто-синхронизация перед отображением списков классов,
     * если с прошлого раза прошло больше SYNC_TTL. Ошибки не бросает.
     */
    public static function ensureSynced(): void
    {
        if (isset($_SESSION['classes_synced_at']) && (time() - (int)$_SESSION['classes_synced_at']) < self::SYNC_TTL) {
            return;
        }
        self::sync();
    }

    /**
     * Вытаскивает номер класса из названия (например "5А" → 5, "11б" → 11).
     */
    private static function parseGrade(string $name): ?int
    {
        if (preg_match('/^\s*(\d{1,2})\s*/u', $name, $m)) {
            return (int)$m[1];
        }
        return null;
    }
}
