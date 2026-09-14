<?php

declare(strict_types=1);

/**
 * Разовое идемпотентное наполнение тьюторов (классных руководителей):
 * создаёт/обновляет записи tutors по логину и перезаписывает привязки
 * tutor_classes, матчируя классы по названию (без учёта регистра).
 *
 * Логины/пароли учёток создаются на портале auth-web (scripts/create_tutors.php
 * проекта auth-web); пароли в журнале не хранятся — этот скрипт их не принимает.
 *
 * Запуск на сервере:  php scripts/seed_tutors.php [путь-к-app.db]
 * Идемпотентно: повторный запуск обновит ФИО и перезапишет привязки.
 *
 * Изменение тьюторов через этот скрипт затирает правки, сделанные в
 * админке журнала — пользуйтесь либо скриптом, либо админкой.
 */

$dbPath = $argv[1] ?? (dirname(__DIR__) . '/db/app.db');
if (!is_file($dbPath)) {
    fwrite(STDERR, "БД не найдена: $dbPath\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

/**
 * Тьюторы: [логин портала] => [ФИО, список классов по названию].
 * Один логин может вести несколько классов (учётка одна).
 */
$tutors = [
    'ЗагуменскаяВА' => ['Загуменская Виктория Алексеевна', ['7а']],
    'ГридасоваНС'   => ['Гридасова Нина Сергеевна',        ['7б', '8б']],
    'ТимченкоЛА'    => ['Тимченко Лилия Анатольевна',      ['7в']],
    'ЗубковаДН'     => ['Зубкова Дарья Николаевна',        ['7г']],
    'КузнецоваЛА'   => ['Кузнецова Людмила Александровна', ['8а', '8в']],
    'ЗайченкоЕН'    => ['Зайченко Елена Николаевна',       ['8г']],
    'ДолининаЮЮ'    => ['Долинина Юлия Юрьевна',           ['9а', '9б']],
    'ЗахароваНВ'    => ['Захарова Наталья Владимировна',   ['9в']],
    'ВерещагинаЕК'  => ['Верещагина Екатерина Константиновна', ['9г']],
    'ЧиндинаНА'     => ['Чиндина Наталья Александровна',   ['10а']],
    'ГришинаГМ'     => ['Гришина Галина Михайловна',       ['10б']],
    'Кулакова-ЛА'   => ['Кулакова Лидия Александровна',    ['10в', '10г']],
    'ШушпановаАО'   => ['Шушпанова Анна Олеговна',         ['11а', '11в', '11г']],
    'СамойловаГИ'   => ['Самойлова Галина Ивановна',       ['11б']],
];

/** Нормализация названия класса: trim + нижний регистр + без пробелов (ASCII и кириллица) */
function norm(string $s): string
{
    $s = trim($s);
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s);
    } else {
        // fallback без mbstring: посимвольная замена регистра в UTF-8
        $upper = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяabcdefghijklmnopqrstuvwxyz';
        $map = array_combine(
            preg_split('//u', $upper, -1, PREG_SPLIT_NO_EMPTY),
            preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY)
        );
        $s = strtr($s, $map);
    }
    // «7 А» из auth-web == «7а» из списка тьюторов
    return (string)preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $s);
}

/** Индекс классов по нормализованному названию */
$classes = [];
foreach ($pdo->query('SELECT id, name FROM classes') as $c) {
    $classes[norm((string)$c['name'])] = (int)$c['id'];
}

$updTutor = $pdo->prepare('UPDATE tutors SET full_name=? WHERE id=?');
$insTutor = $pdo->prepare('INSERT INTO tutors (login, full_name) VALUES (?,?)');
$delLinks = $pdo->prepare('DELETE FROM tutor_classes WHERE tutor_id=?');
$insLink  = $pdo->prepare('INSERT OR IGNORE INTO tutor_classes (tutor_id, class_id) VALUES (?,?)');

$report = ['tutors' => 0, 'added' => 0, 'updated' => 0, 'links' => 0, 'missed_classes' => []];
foreach ($tutors as $login => [$fullName, $classNames]) {
    $st = $pdo->prepare('SELECT id FROM tutors WHERE LOWER(login)=LOWER(?) LIMIT 1');
    $st->execute([trim($login)]);
    $tutorId = $st->fetchColumn();

    if ($tutorId === false) {
        $insTutor->execute([$login, $fullName]);
        $tutorId = (int)$pdo->lastInsertId();
        $report['added']++;
    } else {
        $tutorId = (int)$tutorId;
        $updTutor->execute([$fullName, $tutorId]);
        $report['updated']++;
    }

    // классы: привязываем все найденные, недостающие — в отчёт
    $classIds = [];
    $missed = [];
    foreach ($classNames as $name) {
        $cid = $classes[norm($name)] ?? null;
        if ($cid === null) {
            $missed[] = $name;
        } else {
            $classIds[] = $cid;
        }
    }
    if ($missed) {
        $report['missed_classes'][$login] = $missed;
    }
    $delLinks->execute([$tutorId]);
    foreach ($classIds as $cid) {
        $insLink->execute([$tutorId, $cid]);
        $report['links']++;
    }
    $report['tutors']++;
}

echo "Тьюторов обработано: {$report['tutors']} (добавлено {$report['added']}, обновлено {$report['updated']})\n";
echo "Привязок к классам: {$report['links']}\n";
if ($report['missed_classes']) {
    echo "Классы не найдены (проверьте, что группа создана на портале auth-web, и повторите сид):\n";
    foreach ($report['missed_classes'] as $login => $names) {
        echo "  $login: " . implode(', ', $names) . "\n";
    }
    exit(2);
}
echo "OK\n";
