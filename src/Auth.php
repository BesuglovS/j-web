<?php

/**
 * Аутентификация и проверка ролей.
 *
 * Все роли (администраторы, тьюторы, ученики, родители) авторизуются
 * через единый портал auth.nayanovaacademy.ru (общая кука auth_session).
 * Журнал хранит только локальные зеркала учётных записей без паролей
 * (users: admin — без записей, student/parent — синхронизируются из
 * auth-web; tutors — логины тьюторов, задаются в админке журнала),
 * локального входа нет.
 */
class Auth
{
    // -----------------------------------------------------------
    // Текущий пользователь
    // -----------------------------------------------------------

    public static function user(): ?array
    {
        // SSO через auth-web (общий AuthClient)
        $sso = AuthClient::check();
        if ($sso === null) {
            return null;
        }

        if (!empty($sso['is_admin'])) {
            return [
                'id'       => null,
                'role'     => 'admin',
                'login'    => (string)($sso['login'] ?? ''),
                'full_name' => (string)($sso['display_name'] ?? ''),
                'is_sso'   => true,
            ];
        }

        // Тьютор (классный руководитель): учётка в таблице tutors (логин
        // совпадает с SSO-логином auth-web, паролей в журнале нет)
        $st = Database::pdo()->prepare('SELECT id, login, full_name FROM tutors WHERE LOWER(login)=LOWER(?) LIMIT 1');
        $st->execute([(string)($sso['login'] ?? '')]);
        $tutor = $st->fetch();
        if ($tutor) {
            return [
                'id'        => (int)$tutor['id'],
                'role'      => 'tutor',
                'login'     => (string)$tutor['login'],
                'full_name' => (string)$tutor['full_name'],
                'is_sso'    => true,
            ];
        }

        // Ученик или родитель: локальная привязка по логину (совпадает с логином auth-web)
        $st = Database::pdo()->prepare("SELECT * FROM users WHERE role IN ('student','parent') AND LOWER(login)=LOWER(?) LIMIT 1");
        $st->execute([(string)($sso['login'] ?? '')]);
        $user = $st->fetch();
        if ($user) {
            $user['is_sso'] = true;
            return $user;
        }

        // Авторизован через портал, но локальной записи журнала нет
        return [
            'id'       => null,
            'role'     => null,
            'login'    => (string)($sso['login'] ?? ''),
            'full_name' => (string)($sso['display_name'] ?? ''),
            'is_sso'   => true,
        ];
    }

    /** Пользователь вошёл через единый портал? (единственный способ входа) */
    public static function isSso(): bool
    {
        $u = self::user();
        return $u !== null && !empty($u['is_sso']);
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u && $u['id'] !== null ? (int)$u['id'] : null;
    }

    public static function role(): ?string
    {
        $u = self::user();
        return $u ? $u['role'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    // -----------------------------------------------------------
    // Выход
    // -----------------------------------------------------------

    public static function logout(): void
    {
        AuthClient::clearCache();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // -----------------------------------------------------------
    // Гварды
    // -----------------------------------------------------------

    /**
     * Требует авторизации; для роли — роль должна совпадать (опционально).
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            // Гость — сразу на единый портал, после успешного входа
            // вернём на исходную страницу (deep-link).
            redirect(config()['auth_url'] . '/index.php?page=login&redirect='
                . urlencode(base_url() . ($_SERVER['REQUEST_URI'] ?? '/')));
        }
    }

    public static function requireRole(string $role): void
    {
        self::requireLogin();
        $userRole = self::role();
        if ($userRole !== $role) {
            http_response_code(403);
            exit('Доступ запрещён.');
        }
    }

    /** Редирект в зависимости от роли */
    public static function home(): void
    {
        $role = self::role();
        if ($role === 'admin') {
            redirect('/admin');
        } elseif ($role === 'tutor') {
            redirect('/tutor');
        } elseif ($role === 'student') {
            redirect('/my');
        } elseif ($role === 'parent') {
            redirect('/parent');
        }
        redirect('/login');
    }
}
