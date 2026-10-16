<?php
declare(strict_types=1);

/**
 * Read-only экспорт журнала для проекта asu-win (оценки, темы, домашние задания).
 *
 * Формат ответа совпадает с внутренней моделью jweb.py:
 *   { generated, source, classes: [ { class_id, name, subjects: [
 *       { subject_id, name, lessons: [ { lesson_id, date, topic, homework, marks: [
 *           { mark_id, student_id, fio, value, work_type, comment, is_retake } ] } ] } ] } ] }
 *
 * Доступ — по секретному токену (заголовок X-Export-Token или ?token=),
 * сравнение постоянного времени (hash_equals). Токен читается из файла вне
 * webroot: db/export_token.txt (провизионится deploy.ps1 из .env,
 * JWEB_EXPORT_TOKEN). Если файла нет/пуст — экспорт отключён (503).
 *
 * По умолчанию отдаются только итоговые попытки (marks.is_current=1);
 * ?include_retakes=1 добавляет историю переписываний.
 * Необязательные фильтры: class_id, subject_id, since (Y-m-d), until (Y-m-d).
 */
class ExportController
{
    private function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    /** Ожидаемый токен (первая строка файла) или '' (экспорт выключен). */
    private function expectedToken(): string
    {
        $dbPath = (string)(config()['db_path'] ?? '');
        if ($dbPath === '') {
            return '';
        }
        $file = dirname($dbPath) . '/export_token.txt';
        if (!is_file($file)) {
            return '';
        }
        $line = strtok((string)file_get_contents($file), "\r\n");
        return trim($line === false ? '' : $line);
    }

    /** Фильтры (class_id, subject_id, since, until) в SQL-условие для алиасов таблиц. */
    private function filters(array &$args): string
    {
        $sql = '';
        if (isset($_GET['class_id']) && (int)$_GET['class_id'] > 0) {
            $sql .= ' AND c.id = ?';
            $args[] = (int)$_GET['class_id'];
        }
        if (isset($_GET['subject_id']) && (int)$_GET['subject_id'] > 0) {
            $sql .= ' AND l.subject_id = ?';
            $args[] = (int)$_GET['subject_id'];
        }
        foreach (['since' => '>=', 'until' => '<='] as $key => $op) {
            $val = trim((string)($_GET[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $sql .= ' AND l.date ' . $op . ' ?';
                $args[] = $val;
            }
        }
        return $sql;
    }

    public function grades(): void
    {
        $expected = $this->expectedToken();
        if ($expected === '') {
            $this->json(['error' => 'Экспорт не настроен'], 503);
        }
        $sent = (string)($_SERVER['HTTP_X_EXPORT_TOKEN'] ?? '');
        if ($sent === '' && isset($_GET['token'])) {
            $sent = (string)$_GET['token'];
        }
        if ($sent === '' || !hash_equals($expected, $sent)) {
            $this->json(['error' => 'Не авторизован'], 401);
        }

        $pdo = Database::pdo();

        // 1) Все уроки (класс → предмет → урок): тема и домашние задания.
        $args = [];
        $sql = 'SELECT l.id AS lesson_id, l.date AS lesson_date, l.topic,
                       sub.id AS subject_id, sub.name AS subject_name,
                       c.id AS class_id, c.name AS class_name
                FROM lessons l
                JOIN subjects sub ON sub.id = l.subject_id
                JOIN classes  c   ON c.id = l.class_id
                WHERE 1=1' . $this->filters($args) .
               ' ORDER BY c.name, sub.name, l.date, l.id';
        $st = $pdo->prepare($sql);
        $st->execute($args);

        $classes = [];
        $lessons = [];               // lesson_id => ссылка на массив (для оценок/ДЗ)
        foreach ($st->fetchAll() as $r) {
            $cid = (int)$r['class_id'];
            if (!isset($classes[$cid])) {
                $classes[$cid] = ['class_id' => $cid, 'name' => (string)$r['class_name'], 'subjects' => []];
            }
            $sid = (int)$r['subject_id'];
            if (!isset($classes[$cid]['subjects'][$sid])) {
                $classes[$cid]['subjects'][$sid] = [
                    'subject_id' => $sid, 'name' => (string)$r['subject_name'], 'lessons' => [],
                ];
            }
            $lid = (int)$r['lesson_id'];
            $classes[$cid]['subjects'][$sid]['lessons'][$lid] = [
                'lesson_id' => $lid,
                'date'      => (string)$r['lesson_date'],
                'topic'     => (string)($r['topic'] ?? ''),
                'homework'  => '',
                'marks'     => [],
            ];
            $lessons[$lid] = &$classes[$cid]['subjects'][$sid]['lessons'][$lid];
        }
        unset($r);

        // 2) Домашние задания (может быть несколько на урок — склеиваем).
        $args = [];
        $sql = 'SELECT h.lesson_id, h.title, h.description
                FROM homeworks h
                JOIN lessons  l   ON l.id = h.lesson_id
                JOIN subjects sub ON sub.id = l.subject_id
                JOIN classes  c   ON c.id = l.class_id
                WHERE 1=1' . $this->filters($args) .
               ' ORDER BY h.lesson_id, h.id';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $lid = (int)$r['lesson_id'];
            if (!isset($lessons[$lid])) {
                continue;
            }
            $parts = [];
            $title = trim((string)$r['title']);
            $descr = trim((string)$r['description']);
            if ($title !== '') {
                $parts[] = $title;
            }
            if ($descr !== '') {
                $parts[] = $descr;
            }
            $text = trim(implode(' — ', $parts));
            if ($text !== '') {
                $cur = $lessons[$lid]['homework'];
                $lessons[$lid]['homework'] = $cur === '' ? $text : ($cur . '; ' . $text);
            }
        }

        // 3) Оценки (только итоговые попытки, если не запрошена история).
        $args = [];
        $sql = 'SELECT m.id AS mark_id, m.value, m.work_type, m.comment, m.is_retake, m.is_current,
                       l.id AS lesson_id,
                       s.id AS student_id, s.last_name, s.first_name, s.middle_name
                FROM marks m
                JOIN lessons  l   ON l.id = m.lesson_id
                JOIN subjects sub ON sub.id = l.subject_id
                JOIN classes  c   ON c.id = l.class_id
                JOIN students s   ON s.id = m.student_id
                WHERE 1=1' . $this->filters($args);
        if (empty($_GET['include_retakes'])) {
            $sql .= ' AND m.is_current = 1';
        }
        $sql .= ' ORDER BY l.id, s.last_name, s.first_name';
        $st = $pdo->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $lid = (int)$r['lesson_id'];
            if (!isset($lessons[$lid])) {
                continue;
            }
            $fio = trim(implode(' ', array_filter([
                (string)$r['last_name'], (string)$r['first_name'], (string)$r['middle_name'],
            ], static fn($x) => trim($x) !== '')));
            $lessons[$lid]['marks'][] = [
                'mark_id'    => (int)$r['mark_id'],
                'student_id' => (int)$r['student_id'],
                'fio'        => $fio,
                'value'      => $r['value'] === null ? null : (int)$r['value'],
                'work_type'  => (string)$r['work_type'],
                'comment'    => (string)($r['comment'] ?? ''),
                'is_retake'  => (int)$r['is_retake'],
            ];
        }

        // Приводим подмассивы к спискам и сортируем.
        $outClasses = [];
        foreach ($classes as $c) {
            $subjects = [];
            foreach ($c['subjects'] as $s) {
                $s['lessons'] = array_values($s['lessons']);
                usort($s['lessons'], static fn($a, $b) =>
                    [$a['date'], $a['lesson_id']] <=> [$b['date'], $b['lesson_id']]);
                $subjects[] = $s;
            }
            usort($subjects, static fn($a, $b) => strcmp($a['name'], $b['name']));
            $c['subjects'] = $subjects;
            $outClasses[] = $c;
        }
        usort($outClasses, static fn($a, $b) => strcmp($a['name'], $b['name']));

        $this->json([
            'generated' => date(DATE_ATOM),
            'source'    => 'j-web',
            'classes'   => $outClasses,
        ]);
    }
}
