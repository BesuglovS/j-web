<?php

class ImportService
{
    public static function templates(): array
    {
        $dir = rtrim(config()['data_path'], '/');
        $files = glob($dir . '/*.csv') ?: [];
        $out = [];
        foreach ($files as $f) {
            $out[basename($f)] = file_get_contents($f);
        }
        return $out;
    }

    /**
     * Обрабатывает CSV по типу. $file = элемент $_FILES['csv_file'].
     */
    public static function process(string $kind, array $file): array
    {
        $res = ['ok' => 0, 'fail' => 0, 'errors' => []];
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return $res + ['errors' => ['Ошибка загрузки файла']];
        }
        $rows = self::csv(file_get_contents($file['tmp_name']));
        if (count($rows) < 2) {
            $res['fail'] = 1;
            $res['errors'][] = 'Файл пуст или не содержит данных.';
            return $res;
        }

        switch ($kind) {
            case 'parents':
                foreach (array_slice($rows,1) as $i => $r) {
                    if (empty(array_filter($r))) { continue; }
                    $last = trim($r[0]??''); $first = trim($r[1]??''); $middle = trim($r[2]??'');
                    if ($last === '' && $first === '') { $res['fail']++; $res['errors'][]="Строка ".($i+2).": пустое ФИО"; continue; }
                    $userId = null;
                    if (!empty(trim($r[3]??''))) { $userId = self::ensureUser(trim($r[3]), trim($r[4]??''), 'parent'); }
                    Database::pdo()->prepare('INSERT INTO parents (user_id, last_name, first_name, middle_name) VALUES (?,?,?,?)')
                        ->execute([$userId, $last, $first, $middle]);
                    $res['ok']++;
                }
                break;
            case 'links':
                foreach (array_slice($rows,1) as $i=>$r) {
                    if (empty(array_filter($r))) { continue; }
                    $studentName = trim($r[0]??''); $studentF = trim($r[1]??'');
                    $parentName = trim($r[2]??''); $parentF = trim($r[3]??'');
                    $sid = self::studentId($studentName, $studentF);
                    $pid = self::parentId($parentName, $parentF);
                    if (!$sid || !$pid) { $res['fail']++; $res['errors'][]="Строка ".($i+2).": не найдены студент/родитель"; continue; }
                    Database::pdo()->prepare('INSERT OR IGNORE INTO student_parent (student_id, parent_id) VALUES (?,?)')->execute([$sid,$pid]);
                    $res['ok']++;
                }
                break;
            default:
                $res['fail'] = 1;
                $res['errors'][] = 'Неизвестный тип импорта: '.e($kind);
        }
        return $res;
    }

    private static function csv(string $content): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            if ($line === '' ) { continue; }
            // поддерживаем ; или , — пробуем stmcsv
            $delimiter = (strpos($line, ';') !== false) ? ';' : ',';
            $out[] = str_getcsv(rtrim($line), $delimiter);
        }
        return $out;
    }

    private static function studentId(string $last, string $first): ?int
    {
        $st = Database::pdo()->prepare('SELECT id FROM students WHERE last_name=? AND first_name=? LIMIT 1');
        $st->execute([$last, $first]);
        return ($v=$st->fetchColumn())? (int)$v : null;
    }

    private static function parentId(string $last, string $first): ?int
    {
        $st = Database::pdo()->prepare('SELECT id FROM parents WHERE last_name=? AND first_name=? LIMIT 1');
        $st->execute([$last, $first]);
        return ($v=$st->fetchColumn())? (int)$v : null;
    }

    private static function ensureUser(string $login, string $pass, string $role): ?int
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT id FROM users WHERE login=?');
        $st->execute([$login]);
        if ($u = $st->fetch()) return (int)$u['id'];
        $pdo->prepare('INSERT INTO users (login, password_hash, role) VALUES (?,?,?)')
            ->execute([$login, password_hash($pass !== '' ? $pass : bin2hex(random_bytes(5)), PASSWORD_DEFAULT), $role]);
        return (int)$pdo->lastInsertId();
    }
}