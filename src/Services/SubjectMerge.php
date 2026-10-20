<?php

declare(strict_types=1);

/**
 * Объединение предметов в один (порт логики j-android util/SubjectMerge.kt).
 *
 * Поддерживаются три пары:
 *  1) «Информатика» + «Труд (технология)» в 9 классах;
 *  2) «Информатика» + «Моделирование и пилотирование беспилотных
 *     авиационных систем» в 8 классах;
 *  3) «Информатика» + «Инженерия беспилотных авиационных систем» в 7 классах.
 *
 * Уроки, оценки и средний балл считаются общими; пара применяется к классу,
 * только если в его списке предметов присутствуют оба предмета пары.
 */
class SubjectMerge
{
    private const INFORMATICS_NAME = 'ИНФОРМАТИКА';
    private const TRUD_PREFIX = 'ТРУД';
    private const UAV_SYSTEMS_PREFIX = 'МОДЕЛИРОВАНИЕИПИЛОТИРОВАНИЕ';
    private const BAS_ENGINEERING_PREFIX = 'ИНЖЕНЕРИЯ';

    /**
     * Приведение к верхнему регистру с заменой Ё→Е без mbstring
     * (расширение может отсутствовать). Достаточно для кириллицы.
     */
    private const UPPER_MAP = [
        'а' => 'А', 'б' => 'Б', 'в' => 'В', 'г' => 'Г', 'д' => 'Д', 'е' => 'Е',
        'ё' => 'Е', 'ж' => 'Ж', 'з' => 'З', 'и' => 'И', 'й' => 'Й', 'к' => 'К',
        'л' => 'Л', 'м' => 'М', 'н' => 'Н', 'о' => 'О', 'п' => 'П', 'р' => 'Р',
        'с' => 'С', 'т' => 'Т', 'у' => 'У', 'ф' => 'Ф', 'х' => 'Х', 'ц' => 'Ц',
        'ч' => 'Ч', 'ш' => 'Ш', 'щ' => 'Щ', 'ъ' => 'Ъ', 'ы' => 'Ы', 'ь' => 'Ь',
        'э' => 'Э', 'ю' => 'Ю', 'я' => 'Я', 'Ё' => 'Е',
    ];

    /** Uppercase + замена Ё→Е + удаление пробелов. */
    public static function normalize(string $s): string
    {
        $s = strtr($s, self::UPPER_MAP);
        return preg_replace('/\s+/u', '', $s) ?? '';
    }

    /** 9 класс: по полю grade или по названию («9А», «9 А»). */
    public static function isNinthGrade(?int $grade, ?string $className): bool
    {
        if ($grade === 9) {
            return true;
        }
        if ($grade !== null) {
            return false;
        }
        return str_starts_with(self::normalize((string)$className), '9');
    }

    /** 8 класс: по полю grade или по названию («8А», «8 А»). */
    public static function isEighthGrade(?int $grade, ?string $className): bool
    {
        if ($grade === 8) {
            return true;
        }
        if ($grade !== null) {
            return false;
        }
        return str_starts_with(self::normalize((string)$className), '8');
    }

    /** 7 класс: по полю grade или по названию («7А», «7 А»). */
    public static function isSeventhGrade(?int $grade, ?string $className): bool
    {
        if ($grade === 7) {
            return true;
        }
        if ($grade !== null) {
            return false;
        }
        return str_starts_with(self::normalize((string)$className), '7');
    }

    private static function isInformatics(array $subject): bool
    {
        $key = self::normalize((string)($subject['name'] ?? ''));
        $short = self::normalize((string)($subject['short_name'] ?? ''));
        return $key === self::INFORMATICS_NAME || $short === self::INFORMATICS_NAME;
    }

    private static function isTrudTechnology(array $subject): bool
    {
        $key = self::normalize((string)($subject['name'] ?? ''));
        $short = self::normalize((string)($subject['short_name'] ?? ''));
        return str_starts_with($key, self::TRUD_PREFIX)
            || str_starts_with($short, self::TRUD_PREFIX)
            || str_contains($key, 'ТЕХНОЛОГИЯ')
            || str_contains($short, 'ТЕХНОЛОГИЯ');
    }

    private static function isUavSystems(array $subject): bool
    {
        $key = self::normalize((string)($subject['name'] ?? ''));
        $short = self::normalize((string)($subject['short_name'] ?? ''));
        return str_contains($key, self::UAV_SYSTEMS_PREFIX)
            || str_contains($short, self::UAV_SYSTEMS_PREFIX);
    }

    private static function isBasEngineering(array $subject): bool
    {
        $key = self::normalize((string)($subject['name'] ?? ''));
        $short = self::normalize((string)($subject['short_name'] ?? ''));
        return str_starts_with($key, self::BAS_ENGINEERING_PREFIX)
            || str_starts_with($short, self::BAS_ENGINEERING_PREFIX);
    }

    /**
     * Пара предметов объединённого предмета для класса или null, если класс
     * без объединения либо в его списке нет обоих предметов пары.
     *
     * @param array<int, array<string, mixed>> $subjects предметы класса (name/short_name)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    public static function findPair(?int $grade, ?string $className, array $subjects): ?array
    {
        if (self::isNinthGrade($grade, $className)) {
            return self::matchPair($subjects, self::isInformatics(...), self::isTrudTechnology(...));
        }
        if (self::isEighthGrade($grade, $className)) {
            return self::matchPair($subjects, self::isInformatics(...), self::isUavSystems(...));
        }
        if (self::isSeventhGrade($grade, $className)) {
            return self::matchPair($subjects, self::isInformatics(...), self::isBasEngineering(...));
        }
        return null;
    }

    /**
     * Ищет первый и второй предметы пары по переданным предикатам.
     *
     * @param callable(array<string, mixed>): bool $firstKind
     * @param callable(array<string, mixed>): bool $secondKind
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private static function matchPair(array $subjects, callable $firstKind, callable $secondKind): ?array
    {
        $first = null;
        $second = null;
        foreach ($subjects as $s) {
            if ($first === null && $firstKind($s)) {
                $first = $s;
            }
            if ($second === null && $secondKind($s)) {
                $second = $s;
            }
        }
        return ($first !== null && $second !== null) ? [$first, $second] : null;
    }

    /** Объединённое название предмета: «A и B». */
    public static function displayName(array $first, array $second): string
    {
        return (string)($first['name'] ?? '') . ' и ' . (string)($second['name'] ?? '');
    }
}
